import app from 'flarum/forum/app';

import type Channel from '../../common/models/Channel';
import type Message from '../../common/models/Message';
import type Thread from '../../common/models/Thread';
import type Upload from '../../common/models/Upload';
import { purgeSnapshots, readSnapshot, serialize, writeSnapshot, type Document, signedOutSince } from '../utils/snapshot';

/** Janela de mensagens carregada de um canal e o estado de paginação. */
interface ChannelStream {
  messages: Message[];
  /** Vira false quando uma página volta menor que o tamanho da página. */
  hasMore: boolean;
  loading: boolean;
  loadedInitial: boolean;
  /**
   * Marcador de leitura como estava ao abrir o canal. Congelado de propósito: o
   * divisor "novas mensagens" fica parado enquanto se lê.
   */
  dividerAfterId: number | null;
  /**
   * Semeado do snapshot local, não do servidor. Pintado na hora e trocado pela
   * resposta do servidor na primeira exibição; ver loadChannel() e revalidateStream().
   */
  stale: boolean;
}

interface TypingEntry {
  username: string;
  /** Epoch ms após o qual o indicador sai. */
  expiresAt: number;
  /**
   * Redesenho agendado para quando expira. `typistsIn()` só descarta entradas
   * vencidas quando chamado, e quem para de digitar não gera evento; sem o timer o
   * nome ficaria na tela.
   */
  timer: number;
}

const PAGE_SIZE = 50;

/**
 * Tamanho de página e limite para preencher a lacuna até uma mensagem mais antiga
 * que a janela. 100 é o máximo do endpoint; vinte páginas são duas mil mensagens,
 * acima disso o salto desiste em vez de travar o navegador.
 */
const GAP_PAGE_SIZE = 100;
const GAP_MAX_PAGES = 20;

/** Quantos canais vistos recentemente o snapshot local guarda. */
const SNAPSHOT_CHANNELS = 3;

/** Pausa antes de gravar o snapshot; ver scheduleSnapshot(). */
const SNAPSHOT_DEBOUNCE_MS = 1500;

/** Quando esta aba abriu: uma saída da conta depois disso a impede de gravar. */
const BOOTED_AT = Date.now();

/**
 * Tamanho da coleção informado numa resposta paginada, ou null. `Store#find`
 * pendura o documento cru no array retornado, e o json-api-server põe o total em
 * `meta.page.total`; assim um `limit: 1` também diz quantos existem.
 */
function readTotal(results: unknown): number | null {
  const total = (results as { payload?: { meta?: { page?: { total?: unknown } } } } | null)?.payload?.meta?.page?.total;

  return typeof total === 'number' ? total : null;
}

/**
 * Fonte única de verdade da UI do chat. Os componentes leem daqui e ficam só de
 * apresentação; paginação, leitura, envio otimista e reconciliação de realtime num
 * só lugar mantêm a gaveta e a página em sincronia.
 */
export default class ChatState {
  /** Canais da sidebar, atividade mais recente primeiro. */
  channels: Channel[] = [];
  channelsLoading = false;
  channelsLoaded = false;

  /**
   * Request de canais em andamento, para chamadas simultâneas dividirem a ida ao
   * servidor. Devolver `this.channels` não basta: no início ele ainda está vazio.
   */
  private channelsRequest: Promise<Channel[]> | null = null;

  /**
   * Se os rascunhos já foram buscados na sessão. O composer os grava ao mudar, então
   * a cópia em memória é a mais nova; buscar de novo poderia sobrescrevê-la.
   */
  private draftsLoaded = false;
  private draftsRequest: Promise<void> | null = null;

  /** A sidebar veio do snapshot local; rebusca no primeiro uso. */
  private channelsStale = false;

  /** Canais cuja prévia de fixada veio do snapshot local. */
  private pinnedStale = new Set<number>();

  /** Prévias de fixada sendo buscadas, para prefetch e view dividirem o request. */
  private pinnedInFlight = new Map<number, Promise<void>>();

  /** Gravação de snapshot pendente (debounce), se houver. */
  private snapshotTimer: number | null = null;

  /**
   * Canais vistos na sessão, mais recente primeiro: aqueles cujo histórico o
   * snapshot guarda.
   */
  private recentChannelIds: number[] = [];

  /** Janelas de mensagens carregadas, por id de canal. */
  streams: Record<number, ChannelStream> = {};

  /** Tópicos carregados, por id de canal. */
  threads: Record<number, Thread[]> = {};

  /** Janelas de mensagens dos tópicos abertos, por id de tópico. */
  threadStreams: Record<number, ChannelStream> = {};

  /**
   * Fixada mais recente por canal, para a barra acima do stream. Buscada à parte
   * porque costuma estar bem acima da janela. `null` é "perguntado, nada fixado";
   * `undefined` é "ainda não perguntado".
   */
  pinnedPreviews: Record<number, Message | null> = {};

  /**
   * Quantas fixadas cada canal tem, segundo a última contagem do servidor (lida do
   * meta de paginação do request da prévia). Separado de `pinnedPreviews`: um canal
   * pode ter total sem prévia se o request da prévia falhou.
   */
  private pinnedTotals: Record<number, number> = {};

  /** Rascunhos do composer, por `channelId` ou `channelId:threadId`. */
  drafts: Record<string, string> = {};

  /** Indicadores de digitação, por id de canal e depois de usuário. */
  typing: Record<number, Record<number, TypingEntry>> = {};

  /** Canal e tópico em exibição. */
  activeChannelId: number | null = null;
  activeThreadId: number | null = null;

  /**
   * Se o painel de fixadas está aberto. Divide o slot direito com o painel de
   * tópico; abrir um fecha o outro (ver togglePinned() / setActiveThread()).
   */
  showPinned = false;

  /**
   * Se a gaveta mostra a busca em vez da conversa. Só na gaveta: na página a busca é
   * a rota `chat.search`, e navegar a partir da gaveta a fecharia. Divide o slot de
   * sobreposição com os painéis de tópico e de fixadas.
   */
  showSearch = false;

  /**
   * Gaveta aberta/recolhida. Persistido, para sobreviver ao reload; altere pelos
   * setters abaixo, ou a mudança não é lembrada.
   */
  drawerOpen = false;
  drawerCollapsed = false;

  /**
   * A gaveta fechou para ir à tela cheia, não foi dispensada. Fechada no X fica
   * fechada; a que só saiu da frente volta ao deixar a página.
   */
  drawerSuspended = false;

  /** Modo de seleção, para citar/copiar/mover. */
  selecting = false;
  selected: Set<number> = new Set();

  /**
   * Mensagem sendo respondida ou editada, por escopo do composer (a mesma chave
   * `channelId` / `channelId:threadId` dos rascunhos). Por escopo porque o canal e
   * um tópico aberto desenham dois composers sobre este estado.
   */
  private replyTargets: Record<string, Message> = {};
  private editTargets: Record<string, Message> = {};

  /**
   * Escopos cujo próximo envio deve abrir um tópico novo. Responder e ramificar
   * preparam igual (ambos põem um alvo em `replyTargets`), então isto os distingue.
   */
  private branchTargets: Record<string, true> = {};

  /**
   * Anexos preparados e ainda não enviados, por conversa (`draftKey`), para um
   * anexo de uma DM não sair na próxima mensagem de outro canal.
   */
  private pendingUploadsByKey: Record<string, Upload[]> = {};

  /**
   * Linhas otimistas aguardando resposta, por token gerado no cliente. Aparecem
   * esmaecidas e são trocadas no lugar quando dá certo.
   */
  private pending: Map<string, { channelId: number; token: string }> = new Map();
  private pendingSeq = 0;

  // ── Persistência da gaveta ──

  /** Por usuário, para um navegador compartilhado não restaurar o canal de outra conta. */
  private storageKey(): string | null {
    const id = app.session.user?.id();

    return id ? `ramon-chat.drawer.${id}` : null;
  }

  /**
   * Lê o estado da gaveta salvo pela visita anterior. Retorna se ela deve abrir,
   * para quem chama decidir se carrega a lista de canais.
   */
  restoreDrawer(): boolean {
    const key = this.storageKey();

    if (!key) return false;

    try {
      const raw = localStorage.getItem(key);

      if (!raw) return false;

      const saved = JSON.parse(raw) as {
        open?: boolean;
        collapsed?: boolean;
        suspended?: boolean;
        channelId?: number | null;
      };

      this.drawerOpen = Boolean(saved.open);
      this.drawerCollapsed = Boolean(saved.collapsed);
      // Persistido para um reload na página cheia não perder a reabertura devida.
      this.drawerSuspended = Boolean(saved.suspended);

      if (saved.channelId) {
        this.activeChannelId = Number(saved.channelId);
      }

      return this.drawerOpen;
    } catch {
      // Navegação privada, erro de cota ou lixo editado à mão; não vale quebrar o boot.
      return false;
    }
  }

  private persistDrawer(): void {
    this.persistDrawerCookie();

    const key = this.storageKey();

    if (!key) return;

    try {
      localStorage.setItem(
        key,
        JSON.stringify({
          open: this.drawerOpen,
          collapsed: this.drawerCollapsed,
          suspended: this.drawerSuspended,
          channelId: this.activeChannelId,
        })
      );
    } catch {
      // Não fatal, como acima.
    }
  }

  /**
   * Espelha "gaveta aberta neste canal" num cookie que o servidor lê. O
   * localStorage é invisível ao render, e Content\PreloadChat usa o cookie para pôr
   * a lista e a conversa da gaveta no payload de boot de toda página.
   *
   * Só o id do canal, nunca conteúdo; ausente com a gaveta fechada, para o servidor
   * não trabalhar à toa.
   */
  private persistDrawerCookie(): void {
    try {
      const secure = location.protocol === 'https:' ? '; Secure' : '';

      if (this.drawerOpen && !this.drawerSuspended) {
        document.cookie = `ramon_chat_drawer=${this.activeChannelId ?? 0}; path=/; max-age=31536000; SameSite=Lax${secure}`;
      } else {
        document.cookie = `ramon_chat_drawer=; path=/; max-age=0; SameSite=Lax${secure}`;
      }
    } catch {
      // Não fatal.
    }
  }

  setDrawerOpen(open: boolean): void {
    this.drawerOpen = open;

    // Abrir ou fechar diretamente resolve a questão, então uma suspensão pendente
    // perde a validade. É isto que torna o X definitivo.
    this.drawerSuspended = false;

    this.persistDrawer();
  }

  /**
   * Fecha a gaveta porque a página cheia assume a tela. Diferente de
   * `setDrawerOpen(false)`, que limparia a suspensão, e de `suspendDrawer()`, que
   * criaria uma: chegar à página por outra rota não garante reabertura na saída.
   */
  hideDrawerForPage(): void {
    this.drawerOpen = false;
    this.persistDrawer();
  }

  /**
   * Fecha a gaveta devendo reabri-la. Usado ao passar a conversa para a página
   * cheia, o único caso em que a gaveta some sem o usuário dispensá-la.
   */
  suspendDrawer(): void {
    this.drawerOpen = false;
    this.drawerSuspended = true;
    this.persistDrawer();
  }

  /**
   * Reabre uma gaveta suspensa e diz se havia uma. Consumir a flag aqui torna
   * "restaurar no máximo uma vez" uma propriedade do estado.
   */
  resumeDrawer(): boolean {
    if (!this.drawerSuspended) return false;

    this.drawerSuspended = false;
    this.drawerOpen = true;
    this.persistDrawer();

    return true;
  }

  setDrawerCollapsed(collapsed: boolean): void {
    this.drawerCollapsed = collapsed;
    this.persistDrawer();
  }

  /** Registra o canal em exibição, para o reload reabrir o mesmo. */
  setActiveChannel(channelId: number | null): void {
    // A seleção é do canal em que foi feita: levada para outro, "mover" ou
    // "citar" agiriam também sobre mensagens que já não estão na tela.
    if (channelId !== this.activeChannelId) {
      this.selecting = false;
      this.selected.clear();
    }

    this.activeChannelId = channelId;

    if (channelId !== null) {
      this.recentChannelIds = [channelId, ...this.recentChannelIds.filter((id) => id !== channelId)].slice(0, SNAPSHOT_CHANNELS);
    }

    this.persistDrawer();
  }

  // ── Payload de boot ──

  /** Se o payload de boot já foi lido; só vale uma vez. */
  private bootHydrated = false;

  /**
   * Preenche o estado com o payload que Content\PreloadChat pôs na página, de forma
   * síncrona, para o primeiro frame já ser o chat pronto.
   *
   * Chamado no initializer, antes de montar: `app.data` vem de `Application#load()`,
   * que roda antes dos initializers, mas `app.forum` e `app.session` ainda não
   * existem, então nada aqui pode tocá-los.
   *
   * Cada passo é opcional e protegido; o que o servidor não produziu, ou um stream
   * já carregado, fica para o caminho assíncrono normal.
   */
  hydrateFromBoot(): void {
    if (this.bootHydrated) return;

    this.bootHydrated = true;

    const boot = (app as unknown as { data?: Record<string, any> }).data?.ramonChat;

    if (!boot) return;

    try {
      this.hydrateChannels(boot.channels);
      this.hydrateDrafts(boot.drafts);

      const channelId = Number(boot.channelId ?? 0);

      if (channelId > 0) {
        this.hydrateStream(this.stream(channelId), boot.messages, channelId);
        this.hydratePinned(channelId, boot.pinned);
      }

      const threadId = Number(boot.threadId ?? 0);

      if (threadId > 0) {
        this.hydrateStream(this.threadStream(threadId), boot.threadMessages);
      }
    } catch {
      // Um preload que quebrasse o chat seria pior que o flash que evita; o que não foi
      // hidratado é buscado como sempre.
    }
  }

  /**
   * Semeia o que o payload de boot não trouxe a partir da cópia que a última visita
   * deixou no localStorage: a sidebar e o fim dos canais vistos recentemente.
   *
   * localStorage e não IndexedDB porque a leitura precisa ser síncrona para cair
   * antes do primeiro view(); no tamanho mantido (ver MAX_BYTES em utils/snapshot)
   * custa poucos milissegundos.
   *
   * Nada daqui é confiável além da primeira pintura: cada seção é marcada como velha
   * e trocada pela resposta do servidor em segundo plano (ver loadChannels(),
   * loadChannel() e loadPinnedPreview()). O id do usuário vem de `app.data`, não de
   * `app.session`, pelo mesmo motivo de hydrateFromBoot().
   */
  hydrateFromSnapshot(): void {
    const userId = this.snapshotUserId();

    if (!userId) return;

    const snapshot = readSnapshot(userId);

    if (!snapshot) return;

    try {
      if (!this.channelsLoaded) {
        this.hydrateChannels(snapshot.channels);

        if (this.channelsLoaded) this.channelsStale = true;
      }

      for (const [key, document] of Object.entries(snapshot.streams)) {
        const channelId = Number(key);

        if (!(channelId > 0)) continue;

        const stream = this.stream(channelId);

        if (stream.loadedInitial) continue;

        this.hydrateStream(stream, document, channelId);

        if (stream.loadedInitial) stream.stale = true;
      }

      for (const [key, document] of Object.entries(snapshot.pinned)) {
        const channelId = Number(key);

        if (!(channelId > 0) || channelId in this.pinnedPreviews) continue;

        this.hydratePinned(channelId, document);

        if (channelId in this.pinnedPreviews) this.pinnedStale.add(channelId);
      }
    } catch {
      // Mesma postura do payload de boot.
    }
  }

  private snapshotUserId(): string | null {
    const id = (app as unknown as { data?: { session?: { userId?: unknown } } }).data?.session?.userId;

    return typeof id === 'number' && id > 0 ? String(id) : null;
  }

  /**
   * Grava o snapshot em breve, quando as coisas assentarem. Com debounce porque as
   * mudanças vêm em rajadas, e serializar cinquenta mensagens a cada uma custaria
   * mais que o snapshot economiza.
   */
  scheduleSnapshot(): void {
    if (this.snapshotTimer !== null) return;

    this.snapshotTimer = window.setTimeout(() => {
      this.snapshotTimer = null;
      this.flushSnapshot();
    }, SNAPSHOT_DEBOUNCE_MS);
  }

  /**
   * Grava o snapshot agora. Também é o hook de `pagehide` e aba oculta, para uma
   * mensagem que chegou logo antes de fechar estar no próximo boot. Só o que o
   * servidor confirmou: um stream velho é do snapshot anterior.
   */
  flushSnapshot(): void {
    if (this.snapshotTimer !== null) {
      window.clearTimeout(this.snapshotTimer);
      this.snapshotTimer = null;
    }

    const userId = this.snapshotUserId();

    if (!userId || !this.channelsLoaded || this.channelsStale) return;

    // Outra aba saiu da conta depois que esta abriu: esta ainda acha que há
    // sessão, e gravar agora devolveria ao disco o que a saída apagou.
    if (signedOutSince(BOOTED_AT)) return;

    try {
      const streams: Record<string, Document> = {};
      const pinned: Record<string, Document> = {};

      for (const channelId of this.recentChannelIds) {
        const stream = this.streams[channelId];

        if (stream?.loadedInitial && !stream.loading && !stream.stale) {
          streams[channelId] = serialize(stream.messages.slice(-PAGE_SIZE));
        }

        if (channelId in this.pinnedPreviews && !this.pinnedStale.has(channelId)) {
          const preview = this.pinnedPreviews[channelId];
          const document = preview ? serialize([preview]) : { data: [] };

          pinned[channelId] = {
            ...document,
            meta: { page: { total: this.pinnedTotals[channelId] ?? 0 } },
          };
        }
      }

      writeSnapshot(userId, {
        version: 1,
        savedAt: Date.now(),
        channels: serialize(this.channels),
        streams,
        pinned,
      });
    } catch {
      // Não fatal: a próxima visita carrega normalmente.
    }
  }

  /** Apaga o snapshot de todas as contas; ver utils/snapshot. */
  purgeSnapshots(): void {
    purgeSnapshots();
  }

  /**
   * Coloca um documento JSON:API no store e devolve os models primários. `null` para
   * documento ausente ou malformado ("o servidor não respondeu este"), diferente de
   * `data` vazio, que é resposta real e não deve ser rebuscada.
   */
  private pushDocument<T>(document: unknown): T[] | null {
    if (!document || typeof document !== 'object') return null;

    const data = (document as { data?: unknown }).data;

    if (!Array.isArray(data)) return null;

    const pushed = app.store.pushPayload(document as never) as unknown;

    return (Array.isArray(pushed) ? pushed : [pushed]).filter(Boolean) as T[];
  }

  private hydrateChannels(document: unknown): void {
    if (this.channelsLoaded) return;

    const channels = this.pushDocument<Channel>(document);

    if (channels === null) return;

    this.channels = channels;
    this.channelsLoaded = true;
  }

  private hydrateDrafts(document: unknown): void {
    if (this.draftsLoaded) return;

    const rows = (document as { data?: unknown })?.data;

    if (!Array.isArray(rows)) return;

    for (const row of rows) {
      const { channelId, threadId, content } = row?.attributes ?? {};

      if (channelId && content) {
        this.drafts[this.draftKey(Number(channelId), threadId ? Number(threadId) : null)] = content;
      }
    }

    this.draftsLoaded = true;
  }

  /**
   * Semeia uma janela de mensagens a partir de uma página pré-carregada. A página
   * vem do mais novo para o mais antigo e é invertida, como em `fetchInto`.
   *
   * `channelId` só para stream de canal: é de onde vem o divisor de não lidas, e
   * tópicos não têm marcador de leitura.
   */
  private hydrateStream(stream: ChannelStream, document: unknown, channelId?: number): void {
    if (stream.loadedInitial || stream.loading) return;

    const page = this.pushDocument<Message>(document);

    if (page === null) return;

    if (channelId !== undefined) {
      // Congelado aqui pelo mesmo motivo de loadChannel(): o divisor não pode seguir o
      // marcador enquanto markRead() o atualiza.
      const channel = this.channel(channelId);
      const lastRead = channel?.lastReadMessageId() ?? 0;

      stream.dividerAfterId = lastRead > 0 && (channel?.unreadCount() ?? 0) > 0 ? lastRead : null;
    }

    if (page.length < PAGE_SIZE) stream.hasMore = false;

    stream.messages = page.slice().reverse();
    this.sortStream(stream);
    stream.loadedInitial = true;
    stream.stale = false;
  }

  private hydratePinned(channelId: number, document: unknown): void {
    if (channelId in this.pinnedPreviews) return;

    const pinned = this.pushDocument<Message>(document);

    if (pinned === null) return;

    this.pinnedPreviews[channelId] = pinned[0] ?? null;

    // O documento pré-carregado é o mesmo que o request devolveria, com a mesma
    // contagem. Lido direto, não por `readTotal`, que espera o retorno do store.
    const total = (document as { meta?: { page?: { total?: unknown } } })?.meta?.page?.total;

    this.pinnedTotals[channelId] = typeof total === 'number' ? total : 0;
  }

  // ── Canais ──

  /**
   * Busca a lista de canais da sidebar, no máximo uma vez por sessão. Nada mais a
   * deixa velha: mensagens, joins, saídas e edições chegam pelo websocket e são
   * aplicadas nestes mesmos objetos, e o poller cobre o socket fora do ar.
   *
   * `force` para quando isso não vale: login/logout, ou um join pela página de
   * navegação. `errorHandler` é para o poller, que não deve mostrar o alerta do
   * core quando a sessão acabou.
   */
  async loadChannels(force = false, errorHandler?: (error: { status?: number }) => false | void): Promise<Channel[]> {
    // Aproveita um request já em andamento.
    if (this.channelsRequest) return this.channelsRequest;

    if (this.channelsLoaded && !force) {
      // Lista pintada do snapshot é servida na hora e rebuscada por trás.
      if (this.channelsStale) {
        this.channelsStale = false;
        this.loadChannels(true).catch(() => {});
      }

      return this.channels;
    }

    this.channelsLoading = true;

    this.channelsRequest = (async () => {
      try {
        const results = (await app.store.find(
          'chat-channels',
          {
            filter: { following: true },
            sort: '-lastMessageAt',
            page: { limit: 50 },
          },
          undefined,
          errorHandler ? { errorHandler } : {}
        )) as unknown as Channel[];

        this.channels = Array.isArray(results) ? results : [];
        this.channelsLoaded = true;
        this.channelsStale = false;
        this.scheduleSnapshot();

        return this.channels;
      } finally {
        this.channelsLoading = false;
        this.channelsRequest = null;
        m.redraw();
      }
    })();

    return this.channelsRequest;
  }

  /**
   * Marca um canal recém-criado como carregado e vazio, sem perguntar ao servidor.
   * Só correto para um canal que acabou de ser criado (por isso é método separado):
   * não tem mensagens nem fixada. Economiza dois requests em "Enviar mensagem" no perfil.
   */
  seedEmptyChannel(channelId: number): void {
    const stream = this.stream(channelId);

    if (!stream.loadedInitial && !stream.loading) {
      stream.messages = [];
      stream.hasMore = false;
      stream.dividerAfterId = null;
      stream.loadedInitial = true;
    }

    if (!(channelId in this.pinnedPreviews)) {
      this.pinnedPreviews[channelId] = null;
    }
  }

  /**
   * Põe um canal no topo da sidebar, se ainda não estiver. A lista é por última
   * atividade, e uma conversa recém-iniciada é a mais recente.
   */
  rememberChannel(channel: Channel): void {
    if (this.channels.some((existing) => existing.id() === channel.id())) return;

    this.channels.unshift(channel);
  }

  /**
   * Canal que não existe mais para este leitor: excluído ou fora de alcance. Sai da
   * lista, do stream em cache, da fixada e do snapshot, para um reload não trazê-lo
   * de volta; quem o vê sai dele com um aviso em vez de ficar numa página que só
   * responde 404.
   */
  channelGone(channelId: number): void {
    const wasOpen = this.activeChannelId === channelId && (m.route.get() ?? '').includes(`/chat/c/${channelId}`);
    this.forgetChannel(channelId);

    if (wasOpen) m.route.set(app.route('chat.index'));

    // Só para quem estava olhando: avisar cada exclusão inundaria quem está em muitos
    // canais, um administrador sobretudo.
    if (wasOpen) {
      app.alerts.show({ type: 'warning' }, app.translator.trans('ramon-chat.forum.channel.gone'));
    }

    m.redraw();
  }

  /**
   * Handler de erro para requests de um canal: 403 ou 404 quer dizer que o canal
   * sumiu para o leitor, e `channelGone` resolve em silêncio no lugar do alerta
   * genérico do core. O resto segue adiante.
   */
  private goneHandler(channelId: number) {
    return (error: { status?: number }): false | void => {
      if (error?.status === 403 || error?.status === 404) {
        this.channelGone(channelId);

        return;
      }

      return false;
    };
  }

  /**
   * Tira o canal da sidebar, e da tela se estava aberto. Para uma participação que
   * terminou (saída, remoção, canal privado que deixou de ser visível). O registro
   * no store fica; a próxima busca diz o que ainda é permitido.
   */
  forgetChannel(channelId: number): void {
    this.channels = this.channels.filter((channel) => Number(channel.id()) !== channelId);

    // Sai também do snapshot local: quem foi removido de um canal privado não deve
    // achar o histórico dele no disco.
    delete this.streams[channelId];
    delete this.pinnedPreviews[channelId];
    delete this.pinnedTotals[channelId];
    this.recentChannelIds = this.recentChannelIds.filter((id) => id !== channelId);
    this.scheduleSnapshot();

    if (this.activeChannelId === channelId) {
      this.closeOverlays();
      this.setActiveChannel(null);
    }
  }

  /**
   * Quem quer saber quando a lista de membros de um canal muda. A aba de membros é a
   * única superfície desenhada de uma lista que o payload de realtime não carrega.
   * Listeners em vez de contador no estado: um modal fechado não custa nada.
   */
  private membershipListeners = new Set<(channelId: number) => void>();

  /** Retorna a função que cancela a inscrição. */
  onMembershipChange(listener: (channelId: number) => void): () => void {
    this.membershipListeners.add(listener);

    return () => {
      this.membershipListeners.delete(listener);
    };
  }

  notifyMembershipChange(channelId: number): void {
    this.membershipListeners.forEach((listener) => {
      try {
        listener(channelId);
      } catch {
        // A falha de um listener não pode impedir os outros de serem avisados.
      }
    });
  }

  /**
   * Aquece tudo o que abrir um canal pediria, sem esperar. Chamado no hover e no
   * foco, para o clique cair numa conversa já carregada em navegações do cliente,
   * que não têm payload de boot. As duas chamadas são idempotentes.
   */
  prefetchChannel(channelId: number): void {
    this.loadChannel(channelId).catch(() => {});
    this.loadPinnedPreview(channelId).catch(() => {});
  }

  /**
   * Apaga a lista de canais em cache para a próxima leitura rebuscar. Para mudanças
   * que invalidam a lista inteira: troca de conta, ou join feito onde o model não existe.
   */
  invalidateChannels(): void {
    this.channelsLoaded = false;
  }

  channel(id: number | null): Channel | null {
    if (id === null) return null;

    return (app.store.getById('chat-channels', String(id)) as Channel | undefined) ?? null;
  }

  /** Canais de categoria, para a seção "Canais" da sidebar. */
  categoryChannels(): Channel[] {
    return this.channels.filter((c) => c.isCategory());
  }

  /** Canais diretos e de grupo, para a seção "Mensagens diretas". */
  directChannels(): Channel[] {
    return this.channels.filter((c) => c.isDirect());
  }

  /**
   * Canais com não lidas e quantas menções pendentes. Prefere a lista carregada, que
   * reflete os pushes na hora (`bumpChannel` a incrementa). Sem ela, usa os
   * contadores serializados no usuário da sessão, presentes desde a primeira pintura.
   */
  unreadSummary(): { channels: number; messages: number; mentions: number } {
    if (this.channelsLoaded && this.channels.length > 0) {
      let channels = 0;
      let messages = 0;
      let mentions = 0;

      for (const channel of this.channels) {
        // Canais silenciados não têm badge e não entram na contagem do header.
        if (channel.isMuted()) continue;

        if (channel.hasUnread()) {
          channels++;
          messages += channel.unreadCount() ?? 0;
        }

        if (channel.hasUnreadMentions()) mentions += channel.unreadMentionsCount() ?? 0;
      }

      return { channels, messages, mentions };
    }

    // Antes da lista carregar (o normal com o chat ainda fechado) só existem os
    // contadores serializados; o realtime os mantém (ver bumpUnreadCounters()).
    const user = app.session.user;

    return {
      channels: Number(user?.attribute<number>('chatUnreadChannelsCount') ?? 0),
      messages: Number(user?.attribute<number>('chatUnreadMessagesCount') ?? 0),
      mentions: Number(user?.attribute<number>('chatUnreadMentionsCount') ?? 0),
    };
  }

  /**
   * Move os contadores serializados do ator. O badge do header e o ponto da
   * navegação os usam quando a lista de canais não foi carregada.
   */
  bumpUnreadCounters(messages: number, mentions: number, newChannel: boolean): void {
    const user = app.session.user;

    if (!user) return;

    const at = (key: string) => Number(user.attribute<number>(key) ?? 0);

    user.pushAttributes({
      chatUnreadMessagesCount: Math.max(0, at('chatUnreadMessagesCount') + messages),
      chatUnreadMentionsCount: Math.max(0, at('chatUnreadMentionsCount') + mentions),
      chatUnreadChannelsCount: Math.max(0, at('chatUnreadChannelsCount') + (newChannel ? 1 : 0)),
    });
  }

  // ── Stream de mensagens ──

  stream(channelId: number): ChannelStream {
    if (!this.streams[channelId]) {
      this.streams[channelId] = {
        messages: [],
        hasMore: true,
        loading: false,
        loadedInitial: false,
        dividerAfterId: null,
        stale: false,
      };
    }

    return this.streams[channelId];
  }

  /**
   * Carrega a página mais nova e congela o divisor de não lidas no marcador de
   * leitura da entrada.
   */
  async loadChannel(channelId: number): Promise<void> {
    const stream = this.stream(channelId);

    // Pintado do snapshot e já na tela, então nada é aguardado; a página do servidor
    // o substitui ao chegar.
    if (stream.stale && stream.loadedInitial && !stream.loading) {
      stream.stale = false;
      this.revalidateStream(channelId).catch(() => {});

      return;
    }

    if (stream.loadedInitial || stream.loading) return;

    const channel = this.channel(channelId);
    const lastRead = channel?.lastReadMessageId() ?? 0;

    // Divisor só quando há algo não lido abaixo dele.
    stream.dividerAfterId = lastRead > 0 && (channel?.unreadCount() ?? 0) > 0 ? lastRead : null;

    await this.fetchPage(channelId);

    stream.loadedInitial = true;
  }

  /**
   * Busca uma página mais antiga que a carregada. A API ordena do mais novo para o
   * mais antigo, então a página é invertida antes de ir para o início.
   */
  async fetchPage(channelId: number): Promise<void> {
    await this.fetchInto(this.stream(channelId), { channel: channelId }, this.goneHandler(channelId));
  }

  /**
   * Pagina um stream para trás a partir de um filtro base. Compartilhado por canal e
   * tópico, que são janelas sobre o mesmo endpoint, para não mostrarem históricos
   * diferentes.
   */
  private async fetchInto(
    stream: ChannelStream,
    filter: Record<string, unknown>,
    errorHandler?: (error: { status?: number }) => false | void
  ): Promise<void> {
    if (stream.loading || !stream.hasMore) return;

    stream.loading = true;

    try {
      const oldest = stream.messages[0];

      const results = (await app.store.find(
        'chat-messages',
        {
          filter: {
            ...filter,
            ...(oldest ? { lessThan: Number(oldest.id()) } : {}),
          },
          sort: '-id',
          page: { limit: PAGE_SIZE },
        },
        undefined,
        errorHandler ? { errorHandler } : undefined
      )) as unknown as Message[];

      const page = (Array.isArray(results) ? results : []).slice().reverse();

      if (page.length < PAGE_SIZE) stream.hasMore = false;

      // Merge em vez de concat: um push pode já ter inserido uma destas, e linha
      // duplicada é pior que ausente.
      const known = new Set(stream.messages.map((msg) => msg.id()));
      const fresh = page.filter((msg) => !known.has(msg.id()));

      stream.messages = [...fresh, ...stream.messages];
      this.sortStream(stream);
      this.scheduleSnapshot();
    } finally {
      stream.loading = false;
      m.redraw();
    }
  }

  /**
   * Garante que uma mensagem está na janela carregada do canal, para poder rolar
   * até ela (fixada ou citação antigas). Pagina a lacuna entre a linha mais antiga e
   * o alvo numa só passada, mantendo a janela contígua. Resolve se a mensagem está
   * lá; resposta de tópico ou linha excluída nunca está.
   */
  async revealMessage(channelId: number, messageId: number): Promise<boolean> {
    const stream = this.stream(channelId);
    const has = () => stream.messages.some((message) => Number(message.id()) === messageId);

    if (has()) return true;

    if (!stream.loadedInitial) await this.loadChannel(channelId);

    for (let waited = 0; stream.loading && waited < 100; waited++) {
      await new Promise((resolve) => setTimeout(resolve, 50));
    }

    if (has()) return true;
    if (stream.loading) return false;

    stream.loading = true;

    try {
      for (let i = 0; i < GAP_MAX_PAGES && !has(); i++) {
        const oldest = stream.messages[0];

        if (oldest && Number(oldest.id()) < messageId) break;

        const results = (await app.store.find('chat-messages', {
          filter: {
            channel: channelId,
            greaterThan: messageId - 1,
            ...(oldest ? { lessThan: Number(oldest.id()) } : {}),
          },
          sort: '-id',
          page: { limit: GAP_PAGE_SIZE },
        })) as unknown as Message[];

        const page = (Array.isArray(results) ? results : []).slice().reverse();
        const known = new Set(stream.messages.map((msg) => msg.id()));

        stream.messages = [...page.filter((msg) => !known.has(msg.id())), ...stream.messages];
        this.sortStream(stream);

        if (page.length < GAP_PAGE_SIZE) break;
      }

      this.scheduleSnapshot();
    } catch {
      return false;
    } finally {
      stream.loading = false;
      m.redraw();
    }

    return has();
  }

  /**
   * Troca uma janela semeada pelo snapshot pela página mais nova do servidor, sem
   * await porque a conversa já está desenhada. Dentro do intervalo da página o
   * servidor manda: linhas que não voltam foram excluídas e saem. Linhas mais antigas
   * não foram consultadas e ficam.
   *
   * Se falhar, o snapshot fica sem revalidar; os cursores `greaterThan` e
   * `updatedSince` do poller o alcançam no próximo tick.
   */
  private async revalidateStream(channelId: number): Promise<void> {
    const stream = this.stream(channelId);

    if (stream.loading) return;

    stream.loading = true;

    try {
      const results = (await app.store.find(
        'chat-messages',
        {
          filter: { channel: channelId },
          sort: '-id',
          page: { limit: PAGE_SIZE },
        },
        undefined,
        { errorHandler: this.goneHandler(channelId) }
      )) as unknown as Message[];

      const fresh = (Array.isArray(results) ? results : []).slice().reverse();
      const complete = fresh.length < PAGE_SIZE;
      const oldest = fresh[0] ? Number(fresh[0].id()) : 0;

      const kept = complete ? [] : stream.messages.filter((msg) => Number(msg.id()) < oldest);

      stream.messages = [...kept, ...fresh];
      this.sortStream(stream);

      if (complete) stream.hasMore = false;

      this.scheduleSnapshot();
    } finally {
      stream.loading = false;
      m.redraw();
    }
  }

  // ── Tópicos ──

  /**
   * Janela de mensagens própria de um tópico, por id. Separada de `streams` porque o
   * canal mostra o tópico só como indicador sob a raiz; as respostas nunca estão
   * naquela janela.
   */
  threadStream(threadId: number): ChannelStream {
    if (!this.threadStreams[threadId]) {
      this.threadStreams[threadId] = {
        messages: [],
        hasMore: true,
        loading: false,
        loadedInitial: false,
        // Tópicos não têm marcador de leitura próprio, então não há divisor.
        dividerAfterId: null,
        stale: false,
      };
    }

    return this.threadStreams[threadId];
  }

  async loadThread(threadId: number): Promise<void> {
    const stream = this.threadStream(threadId);

    if (stream.loadedInitial || stream.loading) return;

    await this.fetchThreadPage(threadId);

    stream.loadedInitial = true;
  }

  async fetchThreadPage(threadId: number): Promise<void> {
    await this.fetchInto(this.threadStream(threadId), { thread: threadId });
  }

  /** Carrega o registro do tópico, para o título e a contagem do painel. */
  async findThread(threadId: number): Promise<Thread | null> {
    const known = app.store.getById<Thread>('chat-threads', String(threadId));

    if (known) return known;

    try {
      return ((await app.store.find('chat-threads', String(threadId))) as unknown as Thread) ?? null;
    } catch {
      return null;
    }
  }

  closeThread(): void {
    this.activeThreadId = null;
  }

  /**
   * Fecha o que estiver cobrindo a conversa na gaveta. Chamado ao trocar de canal:
   * uma busca ou lista de fixadas pertence ao canal de onde foi aberta.
   */
  closeOverlays(): void {
    this.showPinned = false;
    this.showSearch = false;
    this.showThreads = false;
    this.activeThreadId = null;
  }

  // ── Fixadas ──

  /**
   * Busca a fixada mais nova acima da janela carregada, uma vez por canal.
   * `latestPinned()` já responde pela janela quando pode; cada busca é uma leitura
   * completa de mensagem (nove policies e quatro resumos de relação).
   *
   * `channelId in this.pinnedPreviews` em vez de truthiness: `null` é "perguntado,
   * nada fixado" e não deve ser repetido. O realtime mantém a resposta correta (ver
   * `onMessageChanged`).
   */
  async loadPinnedPreview(channelId: number, force = false): Promise<void> {
    if (!force && channelId in this.pinnedPreviews) {
      // Do snapshot: já exibido, perguntado de novo por trás.
      if (this.pinnedStale.delete(channelId)) {
        this.loadPinnedPreview(channelId, true).catch(() => {});
      }

      return;
    }

    const inFlight = this.pinnedInFlight.get(channelId);

    if (inFlight) return inFlight;

    const request = this.fetchPinnedPreview(channelId).finally(() => this.pinnedInFlight.delete(channelId));

    this.pinnedInFlight.set(channelId, request);

    return request;
  }

  private async fetchPinnedPreview(channelId: number): Promise<void> {
    try {
      const results = (await app.store.find('chat-messages', {
        filter: {
          channel: channelId,
          pinned: true,
          includeThreadReplies: true,
        },
        sort: '-pinnedAt',
        page: { limit: 1 },
      })) as unknown as Message[];

      this.pinnedPreviews[channelId] = (Array.isArray(results) ? results[0] : null) ?? null;

      // Total de fixadas, que decide se a barra oferece a lista completa. Vem nesta
      // mesma resposta: o json-api-server põe a contagem em toda coleção paginada.
      this.pinnedTotals[channelId] = readTotal(results) ?? 0;
      this.scheduleSnapshot();
    } catch {
      // Barra ausente não vale um erro; a fixação ainda aparece na linha.
      this.pinnedPreviews[channelId] = null;
      this.pinnedTotals[channelId] = 0;
    } finally {
      m.redraw();
    }
  }

  /**
   * Apaga a fixada em cache do canal, para o próximo acesso perguntar ao servidor.
   * Para mensagem fixada acima da janela, que este cliente não tem para atualizar.
   */
  invalidatePinnedPreview(channelId: number): void {
    delete this.pinnedPreviews[channelId];
    delete this.pinnedTotals[channelId];
  }

  /**
   * Quantas fixadas o canal tem: o maior entre a contagem do servidor e as fixadas na
   * janela, pois a contagem não vê fixações posteriores e a janela não vê as de cima.
   *
   * Decide se a barra merece um segundo controle: com uma fixada, a barra já a
   * mostra e o clique salta até ela.
   */
  pinnedCount(channelId: number): number {
    const known = new Set<string>();

    const preview = this.pinnedPreviews[channelId];

    if (preview?.isPinned() && preview.id()) known.add(String(preview.id()));

    for (const message of this.streams[channelId]?.messages ?? []) {
      if (message.isPinned() && !message.isDeleted() && message.id()) {
        known.add(String(message.id()));
      }
    }

    return Math.max(this.pinnedTotals[channelId] ?? 0, known.size);
  }

  /**
   * Mensagem que a barra de fixadas mostra, calculada sobre a janela carregada e a
   * prévia buscada; o `pinnedAt` mais novo vence. Fixar algo visível atualiza a
   * barra sem refetch, e desafixar a mensagem exibida a remove.
   */
  latestPinned(channelId: number): Message | null {
    const candidates: Message[] = [];

    const preview = this.pinnedPreviews[channelId];

    if (preview && preview.isPinned()) candidates.push(preview);

    for (const message of this.streams[channelId]?.messages ?? []) {
      if (message.isPinned() && !message.isDeleted()) candidates.push(message);
    }

    if (candidates.length === 0) return null;

    return candidates.reduce((newest, message) => ((message.pinnedAt()?.getTime() ?? 0) > (newest.pinnedAt()?.getTime() ?? 0) ? message : newest));
  }

  /** Painel de fixadas, busca e painel de tópico dividem um slot. */
  togglePinned(): void {
    this.showPinned = !this.showPinned;

    if (this.showPinned) {
      this.activeThreadId = null;
      this.showSearch = false;
      this.showThreads = false;
    }
  }

  /** Busca, no slot de sobreposição da gaveta. Ver `showSearch`. */
  toggleSearch(): void {
    this.showSearch = !this.showSearch;

    if (this.showSearch) {
      this.activeThreadId = null;
      this.showPinned = false;
      this.showThreads = false;
    }
  }

  /**
   * Lista de tópicos do canal no slot de sobreposição da gaveta. Só na gaveta, como
   * a busca: na página é a rota `chat.threads`, e navegar fecharia a gaveta.
   */
  showThreads = false;

  toggleThreads(): void {
    this.showThreads = !this.showThreads;

    if (this.showThreads) {
      this.activeThreadId = null;
      this.showPinned = false;
      this.showSearch = false;
    }
  }

  /** Cronológico, por id; ids são monotônicos por canal. */
  private sortStream(stream: ChannelStream): void {
    stream.messages.sort((a, b) => Number(a.id()) - Number(b.id()));
  }

  /**
   * Insere ou substitui uma mensagem no stream do canal. Usado pelo envio e pelo
   * realtime, para ordenação e deduplicação ficarem num só lugar.
   */
  upsertMessage(message: Message): void {
    const channelId = message.channelId();

    if (!channelId) return;

    const threadId = message.threadId();

    // Resposta de tópico vai para o painel do tópico aberto. Decidido aqui para
    // realtime e envio não divergirem.
    if (threadId) {
      this.insertInto(this.threadStreams[threadId], message, true);
    }

    // Resposta de tópico não entra na janela do canal: o filtro da API mantém raízes
    // e descarta respostas. Atualizar uma linha já presente é seguro (é a raiz
    // ganhando tópico). Canal nunca aberto não ganha stream; o badge basta.
    this.insertInto(this.streams[channelId], message, !threadId || this.isThreadRoot(message));

    this.scheduleSnapshot();
  }

  private isThreadRoot(message: Message): boolean {
    // `false` quer dizer relação não carregada, e mensagem sem tópico não é raiz. O
    // broadcast de tópico liga a raiz ao chegar, então isto só ocorre para respostas
    // e raízes fora da janela.
    const thread = message.thread();

    if (!thread) return false;

    return thread.originalMessageId() === Number(message.id());
  }

  private insertInto(stream: ChannelStream | undefined, message: Message, appendIfNew: boolean): void {
    if (!stream) return;

    const index = stream.messages.findIndex((msg) => msg.id() === message.id());

    if (index >= 0) {
      stream.messages[index] = message;

      return;
    }

    if (!appendIfNew) return;

    stream.messages.push(message);
    this.sortStream(stream);
  }

  removeMessage(channelId: number, messageId: number): void {
    const stream = this.streams[channelId];

    if (!stream) return;

    stream.messages = stream.messages.filter((msg) => Number(msg.id()) !== messageId);

    this.scheduleSnapshot();
  }

  /**
   * O mesmo, para o painel do tópico. Separado porque streams de tópico ficam em
   * outro mapa: passar um id de tópico a `removeMessage` buscaria entre os canais e
   * poderia apagar a conversa errada.
   */
  removeThreadMessage(threadId: number, messageId: number): void {
    const stream = this.threadStreams[threadId];

    if (!stream) return;

    stream.messages = stream.messages.filter((msg) => Number(msg.id()) !== messageId);
  }

  // ── Envio ──

  /**
   * Envia uma mensagem e retorna o model salvo. O composer é limpo antes da resposta
   * para a digitação seguir; se falhar, o conteúdo volta.
   */
  async send(
    channelId: number,
    content: string,
    options: {
      threadId?: number | null;
      replyToId?: number | null;
      createThread?: boolean;
    } = {}
  ): Promise<Message | null> {
    const trimmed = content.trim();
    const uploadKey = this.draftKey(channelId, options.threadId ?? null);
    const uploadIds = this.pendingUploadsFor(channelId, options.threadId ?? null).map((u) => Number(u.id()));

    if (!trimmed && uploadIds.length === 0) return null;

    const token = `pending-${++this.pendingSeq}`;
    this.pending.set(token, { channelId, token });

    try {
      const payload = await app.request<{ data: any; included?: any[] }>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-messages`,
        body: {
          data: {
            type: 'chat-messages',
            attributes: {
              channelId,
              content: trimmed,
              threadId: options.threadId ?? null,
              replyToId: options.replyToId ?? null,
              createThread: options.createThread ?? false,
              uploadIds,
            },
          },
        },
      });

      const message = app.store.pushPayload<Message>(payload as any);

      this.upsertMessage(message);
      delete this.pendingUploadsByKey[uploadKey];
      this.clearContext(channelId, options.threadId ?? null);

      // Quem envia está em dia por definição.
      const channel = this.channel(channelId);

      if (channel) {
        channel.pushAttributes({
          unreadCount: 0,
          lastReadMessageId: Number(message.id()),
        });
      }

      this.clearDraft(channelId, options.threadId ?? null);

      return message;
    } finally {
      this.pending.delete(token);
      m.redraw();
    }
  }

  hasPendingSends(channelId: number): boolean {
    for (const entry of this.pending.values()) {
      if (entry.channelId === channelId) return true;
    }

    return false;
  }

  // ── Estado de leitura ──

  /**
   * Marca o canal como lido até a mensagem carregada mais nova. Fire-and-forget: um
   * recibo de leitura falho não vale alerta, e a próxima chamada leva um marcador
   * mais novo.
   *
   * Não depende de `hasUnread()`: mensagem que chega no canal aberto é marcada lida
   * sem nunca ganhar badge (`bumpChannel` chama direto), e os recibos de leitura
   * leem o marcador. A comparação abaixo é a trava certa, a mesma que o servidor usa
   * para decidir se transmite.
   */
  markRead(channelId: number): void {
    const channel = this.channel(channelId);

    if (!channel) return;

    const stream = this.streams[channelId];
    const newest = stream?.messages[stream.messages.length - 1];
    const upTo = newest ? Number(newest.id()) : channel.lastMessageId();

    if (!upTo) return;
    if ((channel.lastReadMessageId() ?? 0) >= upTo) return;

    channel.pushAttributes({
      unreadCount: 0,
      unreadMentionsCount: 0,
      lastReadMessageId: upTo,
    });

    this.scheduleSnapshot();

    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-channels/${channelId}/read`,
        body: { data: { attributes: { lastReadMessageId: upTo } } },
        errorHandler: this.goneHandler(channelId),
      })
      .catch(() => {
        // Engolido de propósito; ver acima.
      });
  }

  // ── Rascunhos ──

  draftKey(channelId: number, threadId: number | null = null): string {
    return threadId ? `${channelId}:${threadId}` : String(channelId);
  }

  pendingUploadsFor(channelId: number, threadId: number | null = null): Upload[] {
    return this.pendingUploadsByKey[this.draftKey(channelId, threadId)] ?? [];
  }

  addPendingUpload(channelId: number, threadId: number | null, upload: Upload): void {
    const key = this.draftKey(channelId, threadId);

    this.pendingUploadsByKey[key] = [...(this.pendingUploadsByKey[key] ?? []), upload];
  }

  removePendingUpload(channelId: number, threadId: number | null, id: number): void {
    const key = this.draftKey(channelId, threadId);

    this.pendingUploadsByKey[key] = (this.pendingUploadsByKey[key] ?? []).filter((u) => Number(u.id()) !== id);
  }

  // ── Contexto do composer (resposta / edição), por escopo ──

  replyingTo(channelId: number, threadId: number | null = null): Message | null {
    return this.replyTargets[this.draftKey(channelId, threadId)] ?? null;
  }

  editing(channelId: number, threadId: number | null = null): Message | null {
    return this.editTargets[this.draftKey(channelId, threadId)] ?? null;
  }

  /** Se a resposta preparada começou como ramificação, não como resposta. */
  branchingFrom(channelId: number, threadId: number | null = null): boolean {
    return this.branchTargets[this.draftKey(channelId, threadId)] === true;
  }

  /**
   * Responder e editar se excluem dentro de um escopo. `branch` indica que veio de
   * "responder em tópico"; só o composer lê, para decidir se o envio abre um tópico
   * (ver ChatComposer.submit()).
   */
  setReplyingTo(channelId: number, message: Message | null, threadId: number | null = null, branch = false): void {
    const key = this.draftKey(channelId, threadId);

    delete this.editTargets[key];
    delete this.branchTargets[key];

    if (message) {
      this.replyTargets[key] = message;

      if (branch) this.branchTargets[key] = true;
    } else {
      delete this.replyTargets[key];
    }
  }

  setEditing(channelId: number, message: Message | null, threadId: number | null = null): void {
    const key = this.draftKey(channelId, threadId);

    delete this.replyTargets[key];
    delete this.branchTargets[key];

    if (message) {
      this.editTargets[key] = message;
    } else {
      delete this.editTargets[key];
    }
  }

  clearContext(channelId: number, threadId: number | null = null): void {
    const key = this.draftKey(channelId, threadId);

    delete this.replyTargets[key];
    delete this.editTargets[key];
    delete this.branchTargets[key];
  }

  draft(channelId: number, threadId: number | null = null): string {
    return this.drafts[this.draftKey(channelId, threadId)] ?? '';
  }

  setDraft(channelId: number, content: string, threadId: number | null = null): void {
    const key = this.draftKey(channelId, threadId);

    if (content.trim() === '') {
      delete this.drafts[key];
    } else {
      this.drafts[key] = content;
    }

    this.persistDraft(channelId, threadId, content);
  }

  clearDraft(channelId: number, threadId: number | null = null): void {
    delete this.drafts[this.draftKey(channelId, threadId)];
    this.persistDraft(channelId, threadId, '');
  }

  private draftTimer: number | null = null;

  /**
   * Com debounce para uma tecla não virar request. Rascunhos ficam no servidor para
   * seguir o usuário entre dispositivos, mas não valem uma gravação por caractere.
   */
  private persistDraft(channelId: number, threadId: number | null, content: string): void {
    if (this.draftTimer !== null) window.clearTimeout(this.draftTimer);

    this.draftTimer = window.setTimeout(() => {
      app
        .request({
          method: 'POST',
          url: `${app.forum.attribute('apiUrl')}/chat/drafts`,
          body: { data: { attributes: { channelId, threadId, content } } },
        })
        .catch(() => {});
    }, 1200);
  }

  /**
   * Restaura os rascunhos, no máximo uma vez por sessão. Rebuscar a cada visita
   * competia com uma gravação ainda não concluída e podia trazer a revisão anterior.
   */
  async loadDrafts(): Promise<void> {
    // Rascunhos são da conta e o endpoint é autenticado. A trava fica aqui, não nos
    // três pontos de chamada: um visitante receberia 401 como alerta de erro.
    if (!app.session.user) return;

    if (this.draftsRequest) return this.draftsRequest;
    if (this.draftsLoaded) return;

    this.draftsRequest = this.fetchDrafts().finally(() => {
      this.draftsRequest = null;
    });

    return this.draftsRequest;
  }

  private async fetchDrafts(): Promise<void> {
    try {
      const payload = await app.request<{ data: any[] }>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/chat/drafts`,
      });

      for (const row of payload.data ?? []) {
        const { channelId, threadId, content } = row.attributes ?? {};

        if (channelId && content) {
          this.drafts[this.draftKey(Number(channelId), threadId ? Number(threadId) : null)] = content;
        }
      }

      this.draftsLoaded = true;
    } catch {
      // Rascunho é conveniência e não pode travar a UI. Não marca como carregado, para a
      // próxima visita tentar de novo.
    }
  }

  // ── Digitação ──

  /** Quem está digitando no canal, sem as entradas vencidas. */
  typistsIn(channelId: number): string[] {
    const entries = this.typing[channelId];

    if (!entries) return [];

    const now = Date.now();
    const names: string[] = [];

    for (const [userId, entry] of Object.entries(entries)) {
      if (entry.expiresAt <= now) {
        window.clearTimeout(entry.timer);
        delete entries[Number(userId)];
        continue;
      }

      names.push(entry.username);
    }

    return names;
  }

  noteTyping(channelId: number, userId: number, username: string, typing: boolean, expiresIn = 6): void {
    if (!this.typing[channelId]) this.typing[channelId] = {};

    // O que vier a seguir substitui esta entrada, então o redesenho pendente fica
    // velho; senão cada tecla empilharia um timer.
    const previous = this.typing[channelId][userId];

    if (previous) window.clearTimeout(previous.timer);

    if (!typing) {
      delete this.typing[channelId][userId];

      return;
    }

    // Um pouco depois do prazo, para o redesenho ler uma entrada já vencida.
    const timer = window.setTimeout(() => m.redraw(), expiresIn * 1000 + 100);

    this.typing[channelId][userId] = {
      username,
      expiresAt: Date.now() + expiresIn * 1000,
      timer,
    };
  }

  /**
   * Remove quem digitava na hora: acabou de enviar. Esperar expirar deixaria
   * "X está digitando…" embaixo da mensagem de X.
   */
  clearTyping(channelId: number, userId: number): void {
    this.noteTyping(channelId, userId, '', false);
  }

  private typingSentAt = 0;

  /** Avisa que está digitando, no máximo a cada 3s; sem isso seria um request por tecla. */
  announceTyping(channelId: number): void {
    const now = Date.now();

    if (now - this.typingSentAt < 3000) return;

    this.typingSentAt = now;

    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat/typing`,
        body: { data: { attributes: { channelId, typing: true } } },
        // Fire-and-forget: um sinal recusado (canal fechado, leitor removido) não vale
        // alerta a cada tecla. O push do canal ou a próxima leitura resolvem.
        errorHandler: () => {},
      })
      .catch(() => {});
  }

  // ── Seleção ──

  toggleSelecting(on?: boolean): void {
    this.selecting = on ?? !this.selecting;

    if (!this.selecting) this.selected.clear();
  }

  toggleSelected(messageId: number): void {
    if (this.selected.has(messageId)) {
      this.selected.delete(messageId);
    } else {
      this.selected.add(messageId);
    }
  }

  /**
   * Transforma a seleção em transcrição. No servidor para as regras de citação
   * baterem com as do arquivamento.
   */
  async transcript(format: 'markup' | 'plain' = 'markup'): Promise<string> {
    if (this.selected.size === 0) return '';

    const payload = await app.request<{
      data: { attributes: { content: string } };
    }>({
      method: 'POST',
      url: `${app.forum.attribute('apiUrl')}/chat/transcript`,
      body: {
        data: { attributes: { messageIds: Array.from(this.selected), format } },
      },
    });

    return payload.data?.attributes?.content ?? '';
  }
}
