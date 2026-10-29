import app from 'flarum/forum/app';

import chatState from './state/chat';
import { playNotificationSound } from './utils/sound';
import type Message from '../common/models/Message';
import type Channel from '../common/models/Channel';
import { NotificationLevel } from '../common/models/Channel';
import type { RankBook, RankEntry } from '../common/models/Channel';

/** Nomes dos eventos no fio. Precisam bater com Ramon\Chat\Realtime\BroadcastListener. */
const EVENT_MESSAGE = 'ramonChat.message';
const EVENT_MESSAGE_CHANGED = 'ramonChat.messageChanged';
const EVENT_MESSAGE_PURGED = 'ramonChat.messagePurged';
const EVENT_REACTION = 'ramonChat.reaction';
const EVENT_THREAD = 'ramonChat.thread';
const EVENT_CHANNEL = 'ramonChat.channel';
const EVENT_MEMBERSHIP = 'ramonChat.membership';
const EVENT_MESSAGES_MOVED = 'ramonChat.messagesMoved';
const EVENT_FLAGS = 'ramonChat.flags';
const EVENT_RANKS = 'ramonChat.ranks';
const EVENT_TYPING = 'ramonChat.typing';
const EVENT_PONG = 'ramonChat.pong';

interface UploadPayload {
  id: number;
  fileName: string;
  mimeType: string | null;
  size: number;
  width: number | null;
  height: number | null;
  url: string;
  isImage: boolean;
  createdAt: string | null;
}

interface GroupPayload {
  id: number;
  nameSingular: string;
  namePlural: string;
  color: string | null;
  icon: string | null;
}

interface MessagePayload {
  id: number;
  channelId: number;
  threadId: number | null;
  replyToId: number | null;
  number: number | null;
  userId: number | null;
  type: string;
  systemKey: string | null;
  /** Placeholders interpolados na string de sistema. Ver BroadcastListener. */
  systemData?: Record<string, unknown> | null;
  /** Autor embutido, para quem nunca o viu conseguir desenhar a linha. */
  user?: {
    id: number;
    username: string;
    displayName: string;
    avatarUrl: string | null;
    slug: string;
    /** Só grupos não ocultos; ver BroadcastListener::groupsPayload. */
    groups?: GroupPayload[];
  } | null;
  contentHtml: string | null;
  createdAt: string | null;
  editedAt: string | null;
  isDeleted: boolean;
  /** Se a exclusão foi feita por outra pessoa. Ver BroadcastListener. */
  isModeratorDeleted?: boolean;
  deletedById?: number | null;
  isPinned?: boolean;
  pinnedAt?: string | null;
  uploads?: UploadPayload[];
  /** A quem a mensagem se dirige; controla o destaque e o som. */
  mentionedUsers?: number[];
  mentionsChannelWide?: boolean;
  /** Rank do autor no canal, embutido como o autor. */
  authorRank?: RankEntry | null;
}

/**
 * Liga os eventos do chat no canal privado do ator no websocket. O chat é entregue
 * por usuário, não no canal `public` (ver Ramon\Chat\Realtime\ChatBroadcaster: é
 * uma fronteira de privacidade).
 *
 * Retorna false sem flarum/realtime, para quem chama cair no polling.
 */
let bound = false;

/** Liga os handlers num canal Pusher. Idempotente. */
function bindTo(channel: any): void {
  if (bound || !channel?.bind) return;

  // Envolvido aqui em vez de cada handler marcar a flag: `delivered` vale para
  // qualquer evento, e um handler novo seria um buraco silencioso na prova.
  const on = (event: string, handler: (data: any) => void) =>
    channel.bind(event, (data: any) => {
      if (!delivered) {
        delivered = true;
        // A contagem em andamento foi agendada no ritmo não comprovado; reinicia no ritmo
        // ao vivo.
        onConnectionChange();
      }

      handler(data);
    });

  on(EVENT_MESSAGE, (data: MessagePayload) => onMessage(data));
  on(EVENT_MESSAGE_CHANGED, (data: MessagePayload) => onMessageChanged(data));
  on(EVENT_MESSAGE_PURGED, (data: any) => onMessagePurged(data));
  on(EVENT_REACTION, (data: any) => onReaction(data));
  on(EVENT_THREAD, (data: any) => onThread(data));
  on(EVENT_CHANNEL, (data: any) => onChannel(data));
  on(EVENT_MEMBERSHIP, (data: any) => onMembership(data));
  on(EVENT_MESSAGES_MOVED, (data: any) => onMessagesMoved(data));
  on(EVENT_FLAGS, (data: FlagsPayload) => onFlags(data));
  on(EVENT_RANKS, (data: any) => onRanks(data));
  on(EVENT_TYPING, (data: any) => onTyping(data));
  // Não carrega nada: chegar já é a prova que `on()` registra.
  on(EVENT_PONG, () => m.redraw());

  bound = true;

  // Canal privado só funciona após a autenticação, e um pong antes disso seria
  // descartado pelo daemon. O Pusher dispara isto após cada reinscrição, o que cobre
  // reconexões.
  channel.bind('pusher:subscription_succeeded', () => requestProof());

  if (channel.subscribed) requestProof();

  watchConnection();
}

/**
 * Pede ao servidor um pong no canal do próprio usuário. Sem isto o socket só se
 * comprova no primeiro evento de chat, o que num fórum parado pode nunca acontecer.
 */
function requestProof(): void {
  if (delivered) return;

  app
    .request({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + '/chat/realtime/ping',
      errorHandler: () => {},
    })
    .catch(() => {});
}

/** Estado da conexão Pusher, espelhado para o poller ler barato. */
let connected = false;
let watching = false;
let everConnected = false;

/** Definido por index.tsx; roda quando um socket que caiu volta. */
let onReconnect: () => void = () => {};
/** Definido por index.tsx; roda a cada mudança de estado da conexão. */
let onConnectionChange: () => void = () => {};

export function setConnectionHandlers(handlers: { reconnect: () => void; change: () => void }): void {
  onReconnect = handlers.reconnect;
  onConnectionChange = handlers.change;
}

/**
 * Acompanha o estado do socket: uma queda põe o poller para trabalhar na hora, e a
 * reconexão busca o que foi enviado enquanto estava fora.
 */
function watchConnection(): void {
  if (watching) return;

  const connection = (app as any).websocket?.connection;

  if (!connection?.bind) return;

  watching = true;
  connected = connection.state === 'connected';
  everConnected = connected;

  connection.bind('state_change', (states: { previous: string; current: string }) => {
    const now = states.current === 'connected';

    if (now === connected) return;

    connected = now;
    onConnectionChange();

    if (!now) return;

    if (everConnected) onReconnect();

    everConnected = true;
  });
}

/**
 * Se o socket está conectado e comprovou entrega de ponta a ponta: o único estado
 * em que o polling é puro desperdício.
 */
export function realtimeLive(): boolean {
  return bound && delivered && (connected || !watching);
}

/**
 * Canal privado do usuário, inscrevendo se o realtime ainda não o fez. Lê
 * `app.websocket_channels.user` em vez de `flarum.reg`: um módulo registrado via
 * `addChunkModule` é `undefined` até o chunk rodar, e os handlers nunca seriam
 * ligados. `subscribe()` pode repetir: o Pusher devolve a inscrição existente.
 */
function userChannel(): any | null {
  const ws = (app as any).websocket;
  const channels = (app as any).websocket_channels;

  if (channels?.user) return channels.user;

  const id = app.session.user?.id();

  if (!ws?.subscribe || !id) return null;

  const channel = ws.subscribe('private-user=' + id);

  if (channels) channels.user = channel;

  return channel;
}

/**
 * Liga os eventos do chat no canal privado do ator (ver ChatBroadcaster).
 *
 * Retorna se ligou. Tenta de novo por um tempo porque o realtime cria
 * `app.websocket` no próprio `mount`, e a ordem das extensões não é garantida; sem
 * isso, chegar milissegundos antes rebaixaria o cliente ao polling de vez.
 */
export function bindRealtime(): boolean {
  if (!('flarum-realtime' in (flarum.extensions ?? {}))) return false;

  // O caminho oficial e o mais rápido. O flarum/realtime registra `RealtimeState`
  // no topo do bundle do fórum (fora de initializer e de chunk lazy) justamente para
  // isso: o callback roda quando o canal do usuário é inscrito, ou na hora se já foi.
  if (bindViaRealtimeState()) return true;

  bindTo(userChannel());

  if (bound) return true;

  // Realtime presente mas anterior ao `RealtimeState`, ou sem cliente ainda. Tenta
  // em intervalos curtos; desistir custa um chat 15× mais lento.
  let attempts = 0;

  const timer = window.setInterval(() => {
    attempts++;

    try {
      bindTo(userChannel());
    } catch {
      // Falha transitória no boot não é definitiva.
    }

    if (bound || attempts >= 40) {
      window.clearInterval(timer);

      if (!bound) {
        console.warn('[ramon-chat] websocket unavailable; falling back to polling');
        startPollingFallback();
      }
    }
  }, 100);

  // Reportado como ligado: o retry ou consegue ou inicia o polling sozinho, e
  // retornar false aqui iniciaria um segundo poller.
  return true;
}

/**
 * Registra os handlers pelo callback de prontidão do flarum/realtime. Retorna se o
 * mecanismo existe, não se o canal está pronto: um callback registrado vai
 * disparar, e iniciar o retry junto criaria uma corrida.
 *
 * Por `flarum.reg` porque é módulo de outra extensão; esta entrada vem de um
 * `flarum.reg.add()` no topo do bundle do realtime, então existe assim que ele roda.
 */
function bindViaRealtimeState(): boolean {
  try {
    const state = flarum.reg.get('flarum-realtime', 'forum/RealtimeState') as
      { onUserChannelReady?: (cb: (channel: any) => void) => void } | undefined;

    if (typeof state?.onUserChannelReady !== 'function') return false;

    state.onUserChannelReady((channel: any) => bindTo(channel));

    return true;
  } catch {
    // Realtime antigo ou registro sem a entrada; quem chama lê o canal da instância.
    return false;
  }
}

/** Definido por index.tsx para o retry iniciar o polling sem import circular. */
let startPollingFallback: () => void = () => {};

export function setPollingFallback(fn: () => void): void {
  startPollingFallback = fn;
}

/** Se os handlers do websocket estão ativos, para diagnóstico. */
export function realtimeBound(): boolean {
  return bound;
}

/**
 * Se algo já chegou pelo socket. Diferente de `bound`: a inscrição funciona no
 * cliente seja qual for a situação do servidor, e se o PHP não alcança o daemon o
 * socket parece saudável e nunca entrega nada. Só um evento entregue prova o
 * caminho inteiro; o poller usa isto para decidir o ritmo (ver startPolling() em
 * index.tsx).
 */
export function realtimeDelivered(): boolean {
  return delivered;
}

let delivered = false;

/**
 * Coloca uma mensagem recebida no store e no stream do canal. O payload é uma
 * projeção compacta, não JSON:API, e é traduzido aqui. Relações só são ligadas por
 * id quando o usuário já é conhecido; autor ausente vira linha sem nome em vez de
 * um fetch por mensagem.
 */
function onMessage(data: MessagePayload): void {
  const message = pushMessage(data);

  if (!message) return;

  chatState.upsertMessage(message);

  // O que a pessoa digitava acabou de ser enviado; senão o "digitando…" ficaria
  // embaixo da própria mensagem até expirar.
  if (data.userId) {
    chatState.clearTyping(data.channelId, data.userId);
  }

  bumpChannel(data);
  bumpThread(data);
  announce(data, message);
  queueReconcile(data.channelId, data.id);

  m.redraw();
}

/** Menor id entregue por realtime ainda aguardando leitura oficial, por canal. */
const reconcileFloor = new Map<number, number>();
let reconcileTimer: number | null = null;

/**
 * Atraso antes de reconciliar: uma rajada custa um request, e as ações da linha
 * aparecem enquanto a mensagem ainda está sendo vista.
 */
const RECONCILE_DELAY = 500;

/**
 * Relê da API as mensagens recém-enviadas por push. O payload é o mesmo para todos
 * os membros, então não carrega nada que dependa de quem recebe: `canDelete`,
 * `canEdit`, `canPin` etc. vêm fechados. Reler preenche as flags, e o
 * `defaultInclude` do Index traz as relações que o payload só referencia por id.
 * Agrupado por canal: vinte mensagens custam um request.
 */
function queueReconcile(channelId: number, messageId: number): void {
  const floor = reconcileFloor.get(channelId);

  if (floor === undefined || messageId < floor) {
    reconcileFloor.set(channelId, messageId);
  }

  if (reconcileTimer !== null) return;

  reconcileTimer = window.setTimeout(() => {
    reconcileTimer = null;

    const pending = [...reconcileFloor.entries()];
    reconcileFloor.clear();

    for (const [channel, oldest] of pending) {
      app.store
        .find<Message[]>('chat-messages', {
          // `greaterThan` é exclusivo; volta um para incluir a mais antiga da rajada.
          filter: { channel, greaterThan: oldest - 1 },
          sort: 'id',
          page: { limit: 50 },
        })
        .then((results) => {
          for (const message of (Array.isArray(results) ? results : []) as Message[]) {
            chatState.upsertMessage(message);
          }

          m.redraw();
        })
        // Se falhar, a linha fica como o push montou: visível, sem ações. Resolve no
        // próximo carregamento; não vale alerta.
        .catch(() => {});
    }
  }, RECONCILE_DELAY);
}

/**
 * Toca o som de uma mensagem recebida. Pula se o canal está na tela e a aba em
 * foco, se o canal está silenciado, e conforme o nível de notificação do membro
 * (Nunca, Menções ou Sempre; padrão Menções), igual ao badge.
 *
 * Mensagens próprias não chegam aqui: o ChatBroadcaster exclui o ator.
 */
function announce(data: MessagePayload, message: Message): void {
  const channel = chatState.channel(data.channelId);

  if (channel?.isMuted()) return;

  const watching =
    chatState.activeChannelId === data.channelId && !chatState.drawerCollapsed && document.visibilityState === 'visible' && document.hasFocus();

  if (watching) return;

  // Ausente quer dizer que o canal ainda não está na sidebar; trata como a
  // membership padrão, não como "avise tudo".
  const level = channel?.notificationLevel() ?? NotificationLevel.Mentions;

  if (level === NotificationLevel.Never) return;

  if (level === NotificationLevel.Mentions) {
    // Mensagem direta é dirigida a você por definição.
    const direct = channel?.isDirect() ?? false;

    if (!direct && !message.mentionsActor()) return;
  }

  playNotificationSound();
}

/**
 * Avança a contagem de respostas de um tópico. O servidor incrementa o contador
 * oficial, mas só transmite evento de tópico na criação.
 */
function bumpThread(data: MessagePayload): void {
  if (!data.threadId) return;

  const thread = app.store.getById('chat-threads', String(data.threadId));

  if (!thread) return;

  // Não conta a raiz, que é o tópico, não uma resposta.
  if (thread.attribute<number | null>('originalMessageId') === data.id) return;

  // Nem o que a contagem do tópico já alcança: criar um tópico gera dois broadcasts
  // para uma resposta (o do tópico, cujo `repliesCount` já a inclui, e o da mensagem).
  if (data.id <= Number(thread.attribute<number>('lastMessageId') ?? 0)) return;

  thread.pushAttributes({
    repliesCount: Number(thread.attribute<number>('repliesCount') ?? 0) + 1,
    lastMessageId: data.id,
  });
}

function onMessageChanged(data: MessagePayload): void {
  const existing = app.store.getById('chat-messages', String(data.id)) as Message | undefined;

  // Só reconcilia mensagens já presentes neste cliente.
  if (!existing) {
    // Com uma exceção: a fixação de uma mensagem fora da janela é o que a prévia da
    // barra de fixadas mostra. Sem model para atualizar, apaga a prévia em cache para
    // o próximo acesso perguntar de novo.
    if (data.isPinned) {
      chatState.invalidatePinnedPreview(data.channelId);
    }

    return;
  }

  const wasDeleted = Boolean(existing.isDeleted());

  existing.pushAttributes({
    contentHtml: data.contentHtml,
    editedAt: data.editedAt,
    isDeleted: data.isDeleted,
    isEdited: Boolean(data.editedAt),
    // O texto da lápide depende disto: sem ele, a exclusão por moderador pareceria
    // exclusão do próprio autor.
    isModeratorDeleted: Boolean(data.isModeratorDeleted),
    // Vem no mesmo evento de edições e exclusões: fixar muda o que todos veem primeiro.
    isPinned: Boolean(data.isPinned),
    pinnedAt: data.pinnedAt ?? null,
    ...(data.isDeleted ? { content: null } : {}),
  });

  // Nomeia o moderador quando o destinatário já tem esse usuário; senão a lápide usa
  // o texto sem nome. Não custa request.
  if (data.isModeratorDeleted && data.deletedById) {
    const moderator = app.store.getById('users', String(data.deletedById));

    if (moderator) {
      existing.pushData({ relationships: { deletedBy: moderator } } as any);
    }
  }

  // Excluir ou restaurar muda o que o ator pode fazer com a linha, e isso é por
  // destinatário, então não vem no broadcast (purgar, por exemplo, só é possível
  // depois de excluída). Só quando muda o estado: edição e fixação usam este evento.
  //
  // E só para quem ainda pode ler: mensagem excluída fica visível só para o autor e
  // moderadores, então para os demais seria um request fadado a 404.
  if (Boolean(data.isDeleted) !== wasDeleted && mayStillRead(data)) {
    refreshMessageCapabilities(data.id);
  }

  m.redraw();
}

/**
 * Se o ator ainda leria a mensagem depois de excluída. Espelha
 * ScopeMessageVisibility (autor e quem tem `ramon-chat.moderate`). Conservador: só
 * responde false quando as duas coisas são certamente falsas; errar para o outro
 * lado deixaria o moderador sem os controles.
 */
function mayStillRead(data: MessagePayload): boolean {
  if (app.forum.attribute<boolean>('canModerateChat')) return true;

  const actorId = app.session.user?.id();

  if (actorId === undefined || data.userId === null) return true;

  return String(data.userId) === String(actorId);
}

/** Mensagens com refetch de permissões em andamento. */
const refetchingMessages = new Set<number>();

/**
 * Silencia o core num refetch que pode voltar vazio. 403 ou 404 aqui é resposta
 * ("nada"), não falha: a linha ficou invisível para este ator, caso comum após uma
 * exclusão.
 *
 * `.catch()` não basta: `Application.requestErrorCatch` mostra o alerta antes de
 * rejeitar, então é preciso o `errorHandler` nas opções. Retornar `false` devolve o
 * resto ao handler padrão, e um 500 ainda aparece.
 */
const ignoreNoLongerVisible = (error: { status?: number }): false | void => {
  if (error?.status === 403 || error?.status === 404) return;

  return false;
};

/**
 * Relê uma mensagem para as flags de permissão do ator virem do servidor. O Show
 * tem o mesmo `defaultInclude` da listagem e traz `deletedBy`, que permite à
 * lápide nomear o moderador.
 *
 * Exportado porque o moderador que exclui também precisa: `whenMessageChanged`
 * exclui o ator, então o próprio cliente não recebe o evento. Ver ChatMessage.delete().
 */
export function refreshMessageCapabilities(id: number): void {
  if (refetchingMessages.has(id)) return;

  refetchingMessages.add(id);

  app.store
    .find(
      'chat-messages',
      String(id),
      {},
      {
        errorHandler: ignoreNoLongerVisible,
      }
    )
    // A linha sumiu ou não é mais visível (purga concorrente, ou excluída visível só
    // ao autor e moderadores). O broadcast já aplicou o estado.
    .catch(() => {})
    .then(() => {
      refetchingMessages.delete(id);
      m.redraw();
    });
}

/**
 * Remove uma mensagem purgada. Handler próprio porque não sobra linha: a exclusão
 * comum vira lápide e mantém o lugar, e a purga remove a lápide.
 */
function onMessagePurged(data: { id: number; channelId: number; threadId: number | null }): void {
  chatState.removeMessage(data.channelId, data.id);

  // O painel do tópico usa outro stream, por id de tópico; a purga sai de lá também.
  if (data.threadId) {
    chatState.removeThreadMessage(data.threadId, data.id);

    // A contagem sob a raiz fica um a mais. Nunca abaixo de zero: a resposta pode ter
    // chegado antes deste cliente contá-la.
    const thread = app.store.getById('chat-threads', String(data.threadId));

    if (thread) {
      thread.pushAttributes({
        repliesCount: Math.max(0, Number(thread.attribute<number>('repliesCount') ?? 0) - 1),
      });
    }
  }

  m.redraw();
}

function onReaction(data: { messageId: number; emoji: string; userId: number; added: boolean }): void {
  const message = app.store.getById('chat-messages', String(data.messageId)) as Message | undefined;

  if (!message) return;

  const summary = { ...(message.reactionSummary() ?? {}) };
  const entry = summary[data.emoji] ?? { count: 0, reacted: false };

  const isActor = Number(app.session.user?.id()) === data.userId;

  summary[data.emoji] = {
    count: Math.max(0, entry.count + (data.added ? 1 : -1)),
    // A reação de outra pessoa nunca muda o nosso "reagi".
    reacted: isActor ? data.added : entry.reacted,
  };

  if (summary[data.emoji].count === 0) delete summary[data.emoji];

  message.pushAttributes({ reactionSummary: summary });
  m.redraw();
}

/**
 * Registra um tópico recém-criado ou atualiza um já conhecido. Um tópico novo
 * nunca está no store dos outros, então não basta `getById`.
 *
 * `originalMessageId` vem do payload porque a faixa "N respostas" é desenhada pela
 * relação `thread` da mensagem, e o payload da mensagem só traz `threadId`.
 */
function onThread(data: {
  threadId: number;
  channelId: number;
  originalMessageId: number | null;
  repliesCount: number;
  lastMessageId: number | null;
  title: string | null;
}): void {
  const thread = app.store.pushPayload({
    data: {
      type: 'chat-threads',
      id: String(data.threadId),
      attributes: {
        channelId: data.channelId,
        originalMessageId: data.originalMessageId,
        repliesCount: data.repliesCount,
        // Até onde `repliesCount` vai, para bumpThread() não contar a resposta inicial
        // de novo quando o evento dela chegar.
        lastMessageId: data.lastMessageId,
        title: data.title,
      },
    },
  } as any);

  // Só com a raiz carregada: ligar uma relação a uma mensagem ausente criaria um
  // registro stub que `getById` entregaria como se fosse a mensagem.
  const root = data.originalMessageId !== null ? app.store.getById('chat-messages', String(data.originalMessageId)) : null;

  if (root && thread) {
    // Atributos são mesclados; o resto da linha fica intacto.
    root.pushData({
      attributes: { threadId: data.threadId },
      relationships: { thread },
    } as any);
  }

  m.redraw();
}

interface ChannelPayload {
  channelId: number;
  status: string;
  /** Presente quando o canal foi excluído; nada mais do payload se aplica. */
  deleted?: boolean;
  /**
   * Presente quando o canal foi criado já com este leitor dentro: o outro lado de
   * uma conversa direta, ou um canal de entrada automática.
   */
  created?: boolean;
  imageUrl?: string | null;
  /** Presente quando o canal foi arquivado: quando, e para qual discussão. */
  archivedAt?: string | null;
  archivedDiscussionId?: number | null;
  postPermission?: string;
  isPrivate?: boolean;
  threadingEnabled?: boolean;
  slowModeSeconds?: number;
  name?: string | null;
  emoji?: string | null;
  description?: string | null;
}

/** Canais com refetch de permissões em andamento. */
const refetching = new Set<number>();

function onChannel(data: ChannelPayload): void {
  if (data.deleted) {
    chatState.channelGone(data.channelId);

    return;
  }

  const channel = chatState.channel(data.channelId);

  if (data.created) {
    // Lido pela API, não montado do push, para o registro trazer as flags deste
    // leitor. Espalhado em um segundo e meio: um canal de entrada automática avisa
    // todas as contas ao mesmo tempo.
    if (!channel) {
      window.setTimeout(() => adoptChannel(data.channelId), Math.floor(Math.random() * 1500));
    }

    return;
  }

  if (!channel) return;

  const before = channel.postPermission();
  const slowModeBefore = channel.slowModeSeconds();
  const statusBefore = channel.status();

  channel.pushAttributes({
    status: data.status,
    ...(data.postPermission !== undefined ? { postPermission: data.postPermission } : {}),
    ...(data.isPrivate !== undefined ? { isPrivate: data.isPrivate } : {}),
    ...(data.threadingEnabled !== undefined ? { threadingEnabled: data.threadingEnabled } : {}),
    ...(data.slowModeSeconds !== undefined ? { slowModeSeconds: data.slowModeSeconds } : {}),
    ...(data.name !== undefined ? { name: data.name } : {}),
    ...(data.emoji !== undefined ? { emoji: data.emoji } : {}),
    ...(data.description !== undefined ? { description: data.description } : {}),
    ...(data.imageUrl !== undefined ? { imageUrl: data.imageUrl } : {}),
    ...(data.archivedAt !== undefined ? { archivedAt: data.archivedAt } : {}),
    ...(data.archivedDiscussionId !== undefined ? { archivedDiscussionId: data.archivedDiscussionId } : {}),
  });

  // Parte do que estas configurações decidem é por usuário e não vem no broadcast:
  // `canPostMessage` segue `postPermission`, e `slowModeRemaining` segue o slow mode
  // (quem tem `bypassSlowMode` lê zero). Só relê quando uma regra mudou, para um
  // simples rename não custar um request por membro.
  const permissionMoved = data.postPermission !== undefined && data.postPermission !== before;

  const slowModeMoved = data.slowModeSeconds !== undefined && data.slowModeSeconds !== slowModeBefore;

  // Fechar é igual para todos: ninguém posta num canal fechado, nem administradores
  // (ver ChannelPolicy::postMessage), então o composer some direto pelo push.
  // Reabrir depende de quem é o leitor, e pergunta ao servidor.
  const statusMoved = data.status !== undefined && data.status !== statusBefore;

  if (statusMoved && data.status !== 'open') {
    channel.pushAttributes({ canPostMessage: false });
  }

  // Quem administra a sala precisa saber das ações de estado que passou a ter
  // (fechar libera arquivar, arquivar libera desfazer). Testado nas flags que o
  // leitor já tem, então um membro comum não custa nada ao servidor.
  const runsRoom = channel.canClose() || channel.canArchive() || channel.canUnarchive();

  if (permissionMoved || slowModeMoved || (statusMoved && (data.status === 'open' || runsRoom))) {
    refreshCapabilities(data.channelId);
  }

  m.redraw();
}

interface MembershipPayload {
  channelId: number;
  channelName: string | null;
  isPrivate: boolean;
  userId: number;
  username: string;
  action:
    | 'invited'
    | 'joined'
    | 'left'
    | 'invite_declined'
    | 'invite_cancelled'
    | 'promoted'
    | 'demoted'
    | 'owner_changed'
    | 'transfer_requested'
    | 'transfer_ended';
  actorId: number | null;
  actorName: string | null;
  userCount: number;
  /** Em `owner_changed`: quem era o dono até agora. */
  previousOwnerId?: number | null;
}

/**
 * Alguém foi convidado, entrou ou saiu de um canal. Sobre este usuário, mantém
 * outra aba ou a gaveta em sincronia; sobre outra pessoa, só a contagem e a aba de
 * membros aberta mudam.
 *
 * O payload não traz o canal; quando preciso, ele é buscado pela API, que decide se
 * o usuário pode vê-lo.
 */
function onMembership(data: MembershipPayload): void {
  const me = app.session.user?.id();
  const mine = me !== undefined && String(data.userId) === String(me);
  const channel = chatState.channel(data.channelId);

  if (data.action === 'joined') {
    if (mine) {
      if (channel?.isFollowing()) {
        refreshCapabilities(data.channelId);
      } else {
        adoptChannel(data.channelId);
      }
    } else if (channel) {
      channel.pushAttributes({ userCount: data.userCount });
    }
  } else if (data.action === 'left') {
    if (mine) {
      if (channel) {
        channel.pushAttributes({
          isFollowing: false,
          canPostMessage: false,
          unreadCount: 0,
          unreadMentionsCount: 0,
        });
      }

      const wasOpen = chatState.activeChannelId === data.channelId;

      chatState.forgetChannel(data.channelId);

      // Removido do canal em que está: sai dele em vez de deixar na tela uma conversa
      // que vai recusar toda leitura.
      if (wasOpen && (m.route.get() ?? '').includes(`/chat/c/${data.channelId}`)) {
        m.route.set(app.route('chat.index'));
      }
    } else if (channel) {
      channel.pushAttributes({ userCount: data.userCount });
    }
  } else if (data.action === 'promoted' || data.action === 'demoted') {
    // O papel muda o que o membro pode fazer (fechar, gerir membros), e isso é o
    // servidor quem responde. Os demais só releem a lista de membros, pelo aviso abaixo.
    if (mine && channel) refreshCapabilities(data.channelId);
  } else if (data.action === 'owner_changed') {
    // O selo de dono muda para todos; o que os dois envolvidos podem fazer quem diz é
    // o servidor, então só eles releem o registro.
    const previous = me !== undefined && data.previousOwnerId != null && String(data.previousOwnerId) === String(me);

    if (channel) channel.pushAttributes({ creatorId: data.userId });

    if ((mine || previous) && channel) refreshCapabilities(data.channelId);
  } else if (data.action === 'transfer_requested' || data.action === 'transfer_ended') {
    // Só a aba de membros aberta se importa; a oferta chega ao membro como notificação.
  } else if (data.action === 'invited') {
    if (mine && channel) {
      channel.pushAttributes({
        isInvited: true,
        invitedById: data.actorId,
        invitedByName: data.actorName,
      });
    }
  } else if (mine && channel) {
    // Recusado ou retirado: o registro deixa de indicar convite aberto.
    channel.pushAttributes({
      isInvited: false,
      invitedById: null,
      invitedByName: null,
    });
  }

  chatState.notifyMembershipChange(data.channelId);

  m.redraw();
}

/**
 * Os ranks de um canal mudaram (criado, editado, removido, reordenado, atribuído,
 * ou troca de moderador/dono). O livro inteiro vem junto, igual para todos, e vai
 * para o registro do canal, de onde as linhas de autor leem os ranks. Canal não
 * carregado não tem o que atualizar; as mensagens dele trazem os próprios ranks.
 */
function onRanks(data: { channelId: number; rankBook: RankBook }): void {
  const channel = app.store.getById<Channel>('chat-channels', String(data.channelId));

  if (!channel || !data.rankBook) return;

  channel.pushAttributes({ rankBook: data.rankBook });

  m.redraw();
}

interface MessagesMovedPayload {
  channelId: number;
  /** Na sala de onde saíram: quais linhas remover. */
  movedOut?: number[];
  /** Id da mensagem movida => tópico de onde saiu. */
  threadIds?: Record<string, number> | number[];
  threads?: {
    threadId: number;
    repliesCount: number;
    originalMessageId: number | null;
    lastMessageId: number | null;
  }[];
  /** Na sala para onde foram: quais linhas ler. */
  movedIn?: number[];
  /** Unix time não posterior à movimentação; toda linha movida foi atualizada depois. */
  movedAt?: number;
  messagesCount?: number;
}

/**
 * Mensagens movidas para fora ou para dentro de um canal do leitor. A saída é
 * aplicada pelo push (linhas removidas, também do painel de tópico, e contagens
 * ajustadas). A chegada é lida pela API, pois as regras de visibilidade do destino
 * decidem o que o leitor recebe; a janela é restrita ao intervalo dos ids, então é
 * um request só.
 */
function onMessagesMoved(data: MessagesMovedPayload): void {
  const channel = chatState.channel(data.channelId);

  if (channel && data.messagesCount !== undefined) {
    channel.pushAttributes({ messagesCount: data.messagesCount });
  }

  if (data.movedOut?.length) {
    const threadIds = (data.threadIds ?? {}) as Record<string, number>;

    for (const id of data.movedOut) {
      chatState.removeMessage(data.channelId, id);

      const threadId = threadIds[String(id)];

      if (threadId) chatState.removeThreadMessage(threadId, id);
    }

    for (const thread of data.threads ?? []) {
      const record = app.store.getById('chat-threads', String(thread.threadId));

      record?.pushAttributes({
        repliesCount: thread.repliesCount,
        originalMessageId: thread.originalMessageId,
        lastMessageId: thread.lastMessageId,
      });
    }
  }

  // Só numa janela aberta; canal não carregado busca as linhas ao abrir.
  if (data.movedIn?.length && chatState.streams[data.channelId]) {
    const lowest = Math.min(...data.movedIn);
    const highest = Math.max(...data.movedIn);
    const wanted = new Set(data.movedIn.map(String));

    app.store
      .find<Message[]>(
        'chat-messages',
        {
          filter: {
            channel: data.channelId,
            greaterThan: lowest - 1,
            lessThan: highest + 1,
            ...(data.movedAt ? { updatedSince: data.movedAt } : {}),
          },
          sort: 'id',
          page: { limit: 100 },
        },
        undefined,
        { errorHandler: ignoreNoLongerVisible }
      )
      .then((results) => {
        for (const message of (Array.isArray(results) ? results : []) as Message[]) {
          if (wanted.has(String(message.id()))) chatState.upsertMessage(message);
        }

        m.redraw();
      })
      .catch(() => {});
  }

  m.redraw();
}

/** Recontagem do badge de moderação já agendada. */
let flagsTimer: number | null = null;

interface FlagsPayload {
  /** Mensagens cujas denúncias abertas mudaram, com quantas restam abertas. */
  messages?: { id: number; flagsCount: number }[];
}

/**
 * A fila de moderação mudou: relê a contagem aberta deste moderador. A contagem é
 * por leitor (só denúncias de mensagens que ele vê), então é pedida, não enviada; o
 * documento do fórum a serve. Agrupado, para excluir uma série recontar uma vez.
 *
 * As contagens por mensagem são iguais para todo moderador, então vêm no payload e
 * são aplicadas direto nas linhas carregadas.
 */
function onFlags(data?: FlagsPayload): void {
  if (!app.forum.attribute<boolean>('canModerateChat')) return;

  let marked = false;

  for (const row of data?.messages ?? []) {
    const message = app.store.getById<Message>('chat-messages', String(row.id));

    if (!message) continue;

    message.pushAttributes({ flagsCount: Number(row.flagsCount) || 0 });
    marked = true;
  }

  if (marked) m.redraw();

  if (flagsTimer !== null) return;

  flagsTimer = window.setTimeout(() => {
    flagsTimer = null;

    app
      .request<any>({
        method: 'GET',
        url: app.forum.attribute('apiUrl'),
        errorHandler: ignoreNoLongerVisible,
      } as any)
      .then((payload) => {
        const count = payload?.data?.attributes?.chatOpenFlagsCount;

        if (typeof count === 'number') {
          app.forum.pushAttributes({ chatOpenFlagsCount: count });
        }

        m.redraw();
      })
      .catch(() => {});
  }, 400);
}

/**
 * Busca e lista um canal em que o usuário acabou de entrar. Divide a trava de
 * requests em andamento com `refreshCapabilities`: um join respondido em duas abas
 * não pode buscar duas vezes.
 */
function adoptChannel(channelId: number): void {
  if (refetching.has(channelId)) return;

  refetching.add(channelId);

  app.store
    .find<Channel>(
      'chat-channels',
      String(channelId),
      {},
      {
        errorHandler: ignoreNoLongerVisible,
      }
    )
    .then((channel) => {
      if (channel && !Array.isArray(channel)) {
        chatState.rememberChannel(channel);
      }
    })
    .catch(() => {
      // Afinal não é visível para nós; a sidebar fica como está.
    })
    .then(() => {
      refetching.delete(channelId);
      m.redraw();
    });
}

/**
 * Relê um canal para pegar as flags de permissão do ator. Protegido contra
 * chamadas sobrepostas: uma rajada de edições enfileiraria requests fora de ordem.
 */
function refreshCapabilities(channelId: number): void {
  if (refetching.has(channelId)) return;

  refetching.add(channelId);

  app.store
    .find(
      'chat-channels',
      String(channelId),
      {},
      {
        errorHandler: ignoreNoLongerVisible,
      }
    )
    .catch(() => {
      // O canal pode ter ficado invisível (privado do qual fomos removidos). Melhor
      // manter o registro velho que lançar; a próxima atualização da lista o remove.
    })
    .then(() => {
      refetching.delete(channelId);
      m.redraw();
    });
}

function onTyping(data: { channelId: number; userId: number; username: string; typing: boolean; expiresIn: number }): void {
  chatState.noteTyping(data.channelId, data.userId, data.username, data.typing, data.expiresIn);
  m.redraw();
}

/**
 * Traduz o payload compacto num registro do store. Anexos vão como `included` com
 * a relação to-many `uploads`, no mesmo formato do `defaultInclude` do Create.
 */
function pushMessage(data: MessagePayload): Message | null {
  const uploads = Array.isArray(data.uploads) ? data.uploads : [];

  // O autor vai em `included` com os uploads para a relação `user` resolver; senão
  // quem nunca viu a pessoa veria a linha como "[deleted]".

  // Os grupos do autor vêm junto para os badges aparecerem na linha recebida. Vão
  // como registros `groups` próprios, no formato de `include=user.groups`; senão
  // `user.badges()` lê uma referência não resolvida.
  const groups = data.user?.groups ?? [];
  const groupRecords = groups.map((group) => ({
    type: 'groups',
    id: String(group.id),
    attributes: {
      nameSingular: group.nameSingular,
      namePlural: group.namePlural,
      color: group.color,
      icon: group.icon,
      // Explícito: o broadcast só traz grupos não ocultos
      // (BroadcastListener::groupsPayload), então este é o valor real.
      isHidden: false,
    },
  }));

  const author = data.user
    ? [
        {
          type: 'users',
          id: String(data.user.id),
          attributes: {
            username: data.user.username,
            displayName: data.user.displayName,
            avatarUrl: data.user.avatarUrl,
            slug: data.user.slug,
          },
          // Omitido quando o payload não traz grupos: `[]` apagaria os grupos que um
          // carregamento anterior já pôs no usuário.
          ...(groupRecords.length
            ? {
                relationships: {
                  groups: {
                    data: groupRecords.map(({ type, id }) => ({ type, id })),
                  },
                },
              }
            : {}),
        },
      ]
    : [];

  try {
    return app.store.pushPayload<Message>({
      included: [
        ...author,
        ...groupRecords,
        ...uploads.map((upload) => ({
          type: 'chat-uploads',
          id: String(upload.id),
          attributes: {
            fileName: upload.fileName,
            mimeType: upload.mimeType,
            size: upload.size,
            width: upload.width,
            height: upload.height,
            url: upload.url,
            isImage: upload.isImage,
            createdAt: upload.createdAt,
            // Não nulo: `isPending()` trata messageId nulo como anexo ainda no composer.
            messageId: data.id,
          },
          relationships: data.userId ? { user: { data: { type: 'users', id: String(data.userId) } } } : {},
        })),
      ],
      data: {
        type: 'chat-messages',
        id: String(data.id),
        attributes: {
          channelId: data.channelId,
          threadId: data.threadId,
          replyToId: data.replyToId,
          number: data.number,
          type: data.type,
          systemKey: data.systemKey,
          systemData: data.systemData ?? null,
          contentHtml: data.contentHtml,
          createdAt: data.createdAt,
          editedAt: data.editedAt,
          isDeleted: data.isDeleted,
          isModeratorDeleted: Boolean(data.isModeratorDeleted),
          isEdited: Boolean(data.editedAt),
          isPinned: Boolean(data.isPinned),
          pinnedAt: data.pinnedAt ?? null,
          reactionSummary: {},
          // Lidos do payload: sem eles a mensagem ao vivo não seria reconhecida como menção
          // (nem destaque, nem som).
          mentionedUsers: Array.isArray(data.mentionedUsers) ? data.mentionedUsers : [],
          mentionsChannelWide: Boolean(data.mentionsChannelWide),
          authorRank: data.authorRank ?? null,
          isBookmarked: false,
          // Flags de permissão fechadas: o push não as conhece, e oferecer uma ação que o
          // servidor recusaria é pior que esperar o refetch.
          canEdit: false,
          canDelete: false,
          canReact: true,
          canReply: true,
          canCreateThread: false,
          canMove: false,
          canPin: false,
        },
        relationships: {
          ...(data.userId ? { user: { data: { type: 'users', id: String(data.userId) } } } : {}),
          // A citação acima da resposta vem desta relação, não de `replyToId`. Basta a
          // linkage: só se responde ao que se vê, então o alvo já está no store. Se saiu da
          // janela, `hasOne` dá undefined e `replyPreview` não renderiza nada.
          ...(data.replyToId
            ? {
                replyTo: {
                  data: {
                    type: 'chat-messages',
                    id: String(data.replyToId),
                  },
                },
              }
            : {}),
          // Sempre enviado, mesmo vazio: `hasMany` retorna false para relação ausente, e a
          // linha não distinguiria "sem anexos" de "não carregado".
          uploads: {
            data: uploads.map((upload) => ({
              type: 'chat-uploads',
              id: String(upload.id),
            })),
          },
        },
      },
    } as any);
  } catch {
    return null;
  }
}

/**
 * Avança o ponteiro de última mensagem e o badge de não lidas localmente: o servidor
 * já fez o incremento oficial, e um request por mensagem anularia o websocket.
 */
function bumpChannel(data: MessagePayload): void {
  const channel = chatState.channel(data.channelId);

  // Canal fora da lista carregada (em geral o chat não foi aberto na sessão). O
  // badge do canal não tem onde ficar, mas a contagem do header e o ponto da
  // navegação leem os contadores do ator e precisam mudar.
  if (!channel) {
    chatState.bumpUnreadCounters(1, 0, true);

    // Tráfego num canal que a lista não conhece indica lista atrasada (convite aceito
    // em outro lugar, DM aberta por outra pessoa). A lista só é buscada uma vez por
    // sessão; apagar o cache faz a próxima leitura ir ao servidor.
    chatState.invalidateChannels();

    return;
  }

  const attrs: Record<string, unknown> = {
    lastMessageId: data.id,
    lastMessageAt: data.createdAt,
  };

  const isActive = chatState.activeChannelId === data.channelId && !chatState.drawerCollapsed;

  if (isActive) {
    // Está lendo agora: avisa o servidor, sem badge.
    chatState.markRead(data.channelId);
  } else if (!channel.isMuted()) {
    const before = channel.unreadCount() ?? 0;

    attrs.unreadCount = before + 1;

    // `newChannel` só quando o canal passou de nada não lido para algo: o contador
    // conta canais, não mensagens.
    chatState.bumpUnreadCounters(1, 0, before === 0);
  }

  channel.pushAttributes(attrs);
}
