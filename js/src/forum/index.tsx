import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import HeaderSecondary from 'flarum/forum/components/HeaderSecondary';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import UserControls from 'flarum/forum/utils/UserControls';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import type ItemList from 'flarum/common/utils/ItemList';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';

import Channel from '../common/models/Channel';
import Message from '../common/models/Message';
import Thread from '../common/models/Thread';
import Upload from '../common/models/Upload';
import MessageFlag from '../common/models/MessageFlag';

import chatState from './state/chat';
import ChatState from './state/ChatState';
import ChatNavButton from './components/ChatNavButton';
import ChatDrawer, { loadDrawerPanel } from './components/ChatDrawer';
import ChannelInviteNotification from './components/ChannelInviteNotification';
import ChannelInviteDeclinedNotification from './components/ChannelInviteDeclinedNotification';
import MessageFlaggedNotification from './components/MessageFlaggedNotification';
import ModeratorPromotedNotification from './components/ModeratorPromotedNotification';
import OwnershipTransferNotification from './components/OwnershipTransferNotification';
import OwnershipTransferDeclinedNotification from './components/OwnershipTransferDeclinedNotification';
import OwnershipInheritedNotification from './components/OwnershipInheritedNotification';
import { bindRealtime, setPollingFallback, setConnectionHandlers, realtimeBound, realtimeDelivered, realtimeLive } from './realtime';
import { bindShortcuts } from './utils/shortcuts';
import { installComposerStacking } from './utils/stacking';
import { shouldUseChatDrawer } from './utils/surface';
import { chatTitle, chatIcon } from './utils/branding';
import ChatPageResolver from './resolvers/ChatPageResolver';
import bindFlagsIntegration from './utils/flagsIntegration';
import { loadChatUi } from './utils/lazy';

export { default as extend } from './extend';

export {
  Channel,
  Message,
  Thread,
  Upload,
  MessageFlag,
  ChatState,
  chatState,
  ChatNavButton,
  ChatDrawer,
  ChannelInviteNotification,
  ChannelInviteDeclinedNotification,
  MessageFlaggedNotification,
  ModeratorPromotedNotification,
  OwnershipTransferNotification,
  OwnershipTransferDeclinedNotification,
  OwnershipInheritedNotification,
  // Exposto para diagnóstico no console:
  //   flarum.extensions['ramon-chat'].realtimeBound()
  // diz se o chat está no websocket ou no polling.
  realtimeBound,
  // E se algo já chegou por ele. `realtimeBound() === true` com
  // `realtimeDelivered() === false` após algum tráfego indica que o PHP não alcança
  // o daemon do websocket.
  realtimeDelivered,
  // Conectado e comprovado: o estado em que o poller recua.
  realtimeLive,
};

/**
 * Intervalo de polling sem websocket. 3s mantém uma conversa usável; o poll é
 * pulado com a aba oculta e só pede mensagens mais novas que a última conhecida.
 */
const POLL_INTERVAL = 3000;

/**
 * Intervalo com o websocket inscrito mas sem nunca ter entregado. A inscrição só
 * prova que o cliente alcança o daemon; se o servidor não alcança, isto limita a
 * falha a alguns segundos.
 */
const POLL_INTERVAL_UNPROVEN = 15000;

/**
 * Intervalo com o socket conectado e comprovado; na prática desligado. Queda de
 * conexão já reinicia o poller pela mudança de estado, então sobra só a rede de
 * segurança para um evento perdido.
 */
const POLL_INTERVAL_PROVEN = 300000;

/**
 * Intervalo mínimo entre atualizações da lista de canais, seja qual for o poll. A
 * lista é o pedido mais caro (cinquenta linhas, cada uma com membership e dez
 * policies); o fim das mensagens é uma leitura por keyset, geralmente vazia.
 * Separados, a conversa segue a 3s e a lista fica alguns segundos atrás.
 */
const CHANNEL_POLL_INTERVAL = 15000;

/**
 * O mesmo piso com o socket comprovado. Tudo o que a lista carrega chega pelo
 * socket, então isto só cobre um daemon que morre no meio da sessão.
 */
const CHANNEL_POLL_INTERVAL_PROVEN = 60000;

app.initializers.add('ramon-chat', () => {
  // Antes de montar qualquer coisa (os models vêm de ./extend, aplicado antes dos
  // initializers): lista de canais, conversa aberta, mensagem fixada e rascunhos já
  // vêm na página (ver Content\PreloadChat), e o chat pinta pronto.
  //
  // Não pode esperar `app.mount`: o router já teria montado o ChatPage sem dados.
  // `app.data` existe antes dos initializers; `app.forum` e `app.session` não, e
  // hydrateFromBoot não os toca.
  chatState.hydrateFromBoot();

  // Depois a cópia local, para o que a página não trouxe: a sidebar e os últimos
  // canais vistos na visita anterior. Síncrono pelo mesmo motivo; revalidado ao exibir.
  chatState.hydrateFromSnapshot();

  // ── Rotas ──
  // Os nomes batem com extend.php. As rotas do ChatPage dividem um resolver de key
  // constante, então navegar entre elas redesenha em vez de remontar (ver
  // ChatPageResolver). A página fica no chunk chatUi; o loader resolve sempre a
  // mesma classe, então a key constante continua valendo.
  const chatPage = {
    component: () => loadChatUi().then((ui) => ({ default: ui.ChatPage })),
    resolverClass: ChatPageResolver,
  };
  const browsePage = () => loadChatUi().then((ui) => ({ default: ui.BrowseChannelsPage }));

  app.routes['chat.index'] = { path: '/chat', ...chatPage };
  app.routes['chat.channel'] = { path: '/chat/c/:id', ...chatPage };
  app.routes['chat.thread'] = { path: '/chat/c/:id/t/:threadId', ...chatPage };
  app.routes['chat.threads'] = { path: '/chat/threads', ...chatPage };
  app.routes['chat.search'] = { path: '/chat/search', ...chatPage };
  app.routes['chat.bookmarks'] = { path: '/chat/bookmarks', ...chatPage };
  app.routes['chat.flags'] = { path: '/chat/flags', ...chatPage };

  // Página de fato separada, com o resolver padrão.
  app.routes['chat.browse'] = {
    path: '/chat/browse',
    component: browsePage,
  };
  app.routes['chat.browse.filter'] = {
    path: '/chat/browse/:filter',
    component: browsePage,
  };

  // Aquece a UI do chat quando o fórum fica ocioso. Checado na hora, após o mount e
  // com a sessão conhecida: visitante ou membro que desativou o chat não baixa nada.
  app.prefetch?.add('ramon-chat-ui', () => (canUseChat() ? loadChatUi() : Promise.resolve()), -10);

  // ── Notificações ──
  // Os componentes dos alertas vêm de ./extend; aqui fica a linha nas preferências
  // de notificação.
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<unknown>) {
    items.add('chatChannelInvite', {
      name: 'chatChannelInvite',
      icon: 'fas fa-envelope-open',
      label: app.translator.trans('ramon-chat.forum.settings.notify_channel_invite'),
    });

    items.add('chatChannelInviteDeclined', {
      name: 'chatChannelInviteDeclined',
      icon: 'fas fa-user-xmark',
      label: app.translator.trans('ramon-chat.forum.settings.notify_channel_invite_declined'),
    });

    items.add('chatModeratorPromoted', {
      name: 'chatModeratorPromoted',
      icon: 'fas fa-user-shield',
      label: app.translator.trans('ramon-chat.forum.settings.notify_moderator_promoted'),
    });

    items.add('chatOwnershipTransfer', {
      name: 'chatOwnershipTransfer',
      icon: 'fas fa-crown',
      label: app.translator.trans('ramon-chat.forum.settings.notify_ownership_transfer'),
    });

    items.add('chatOwnershipTransferDeclined', {
      name: 'chatOwnershipTransferDeclined',
      icon: 'fas fa-user-xmark',
      label: app.translator.trans('ramon-chat.forum.settings.notify_ownership_transfer_declined'),
    });

    items.add('chatOwnershipInherited', {
      name: 'chatOwnershipInherited',
      icon: 'fas fa-crown',
      label: app.translator.trans('ramon-chat.forum.settings.notify_ownership_inherited'),
    });

    // Só administradores, que são quem o servidor notifica.
    if (app.session.user?.isAdmin()) {
      items.add('chatMessageFlagged', {
        name: 'chatMessageFlagged',
        icon: 'fas fa-flag',
        label: app.translator.trans('ramon-chat.forum.settings.notify_message_flagged'),
      });
    }
  });

  // ── Botão do header ──
  extend(HeaderSecondary.prototype, 'items', function (items) {
    if (!canUseChat()) return;

    items.add('chat', <ChatNavButton />, 15);
  });

  // ── Navegação do fórum ──
  // Mesma lista de "Todas as discussões", "Tags" e "Mensagens":
  // `IndexSidebar#navItems`. No celular o core a põe no `SelectDropdown`
  // `App-titleControl` da toolbar, então isto já põe o chat no menu mobile.
  //
  // `LinkButton` para `chat.index`, sempre a página cheia: um item de navegação que
  // às vezes abre um painel seriam dois controles com um rótulo. Prioridade 90 fica
  // abaixo de "Todas as discussões" (100) e de "Mensagens" (95).
  extend(IndexSidebar.prototype, 'navItems', function (items) {
    if (!canUseChat()) return;

    items.add(
      'chat',
      <LinkButton href={app.route('chat.index')} icon={chatIcon() ?? undefined}>
        {chatTitle()}
      </LinkButton>,
      90
    );
  });

  // ── "Chat" no perfil e no dropdown de controles do usuário ──
  // Mesmo ponto que o flarum/messages usa para "Enviar mensagem".
  //
  // Nomeado em vez de inline: `@ts-ignore` só vale para a próxima linha, e o
  // formatter quebraria a chamada longa em várias. A tipagem publicada do
  // UserControls perde o parâmetro `user`, embora ele seja passado em runtime.
  const addChatToUserControls = (items: ItemList<Mithril.Children>, user: User) => {
    if (!canUseChat()) return;
    if (!app.forum.attribute<boolean>('canStartChatDirect')) return;

    if (app.session.user?.id() === user.id()) return;

    // Com o flarum/messages ativo já existe "Enviar mensagem"; outra entrada para DM
    // do chat seria redundante.
    if ('flarum-messages' in flarum.extensions) return;

    items.add(
      'chatDirect',
      <Button icon="fas fa-envelope" onclick={() => startDirectMessage(user)}>
        {app.translator.trans('ramon-chat.forum.user_controls.start_chat')}
      </Button>,
      // Logo abaixo do botão do flarum/messages, que fica na prioridade padrão 0.
      -5
    );
  };

  // @ts-ignore
  extend(UserControls, 'userControls', addChatToUserControls);

  // O que lê `app.forum`/`app.session` ou monta uma raiz Mithril própria precisa
  // rodar depois de ForumApplication.mount(): o boot() roda os initializers antes de
  // preencher `forum` e `session`, e o mount() é quem chama m.route().
  //
  // `app.beforeMount()` não serve: runBeforeMount() não tem try/catch, e um erro ali
  // impede o mount() e a SPA não renderiza.
  //
  // Estende a instância `app`, não ForumApplication.prototype: a classe fica num
  // chunk lazy e importá-la aqui dá `undefined`. A propriedade própria sombreia o
  // método do protótipo, e o boot() chama `this.mount()`.
  extend(app, 'mount', function () {
    // O core também não protege esta chamada, e o chat nunca pode derrubar o fórum.
    try {
      // Sem sessão: nada das conversas da última conta fica no disco.
      if (!app.session.user) chatState.purgeSnapshots();

      if (!canUseChat()) return;

      // ── Atualizações ao vivo ──
      // Os dois, sempre. O websocket traz as mensagens; o poller é a rede de segurança,
      // ajusta o ritmo pelo que o socket comprovou e é idempotente (só pede o que é mais
      // novo). Cobre o caso em que o cliente se inscreve mas o servidor não alcança o
      // daemon e nada chega.
      setPollingFallback(startPolling);
      setConnectionHandlers({
        reconnect: () => {
          lastChannelPoll = 0;
          poll();
        },
        change: () => reschedulePolling(),
      });
      bindRealtime();
      startPolling();

      // Voltar à aba ou à rede pede na hora o que chegou no meio tempo; sem isto uma
      // aba acordada podia ficar até um minuto atrás. Ocultar a aba grava o snapshot.
      document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
          chatState.flushSnapshot();
        } else if (!realtimeLive()) {
          poll();
        }
      });
      window.addEventListener('online', () => {
        if (!realtimeLive()) poll();
      });
      window.addEventListener('pagehide', () => chatState.flushSnapshot());

      // ── Gaveta ──
      // Raiz própria fora da árvore da página, para navegar no fórum não desmontar a
      // conversa aberta. Não renderiza nada até abrir.
      mountDrawer();

      bindShortcuts();
      installComposerStacking();

      // Denúncias do chat na lista do flarum/flags, quando presente.
      bindFlagsIntegration();

      // Reabre se a última visita a deixou aberta. Só o botão de fechar (e ir para a
      // página cheia) limpa isto.
      if (chatState.restoreDrawer()) {
        loadDrawerPanel().catch(() => {});

        Promise.all([chatState.loadChannels(), chatState.loadDrafts()])
          .catch(() => {})
          .then(() => m.redraw());
      }
    } catch (e) {
      console.error('[ramon-chat] failed to start:', e);
    }
  });
});

/**
 * Abre (ou cria) um canal direto com o usuário e o mostra. O endpoint só reusa a
 * conversa enquanto todos os participantes estão nela; depois que alguém sai, um
 * novo início abre outro canal.
 */
export async function startDirectMessage(user: User): Promise<void> {
  try {
    const payload = await app.request<{
      data?: { id?: string };
      meta?: { created?: boolean; serialized?: boolean };
    }>({
      method: 'POST',
      url: `${app.forum.attribute('apiUrl')}/chat/direct`,
      body: { data: { attributes: { userIds: [Number(user.id())] } } },
    });

    const channelId = Number(payload?.data?.id);

    if (!channelId) return;

    // O endpoint serializa o canal encontrado ou criado, então a conversa chega com o
    // POST (ver StartDirectController::serialize).
    let channel: Channel | null = null;

    if (payload.meta?.serialized) {
      try {
        channel = app.store.pushPayload(payload as never) as unknown as Channel;
      } catch {
        // Cai no fetch abaixo.
      }
    }

    if (!channel) {
      try {
        channel = (await app.store.find('chat-channels', String(channelId))) as unknown as Channel;
      } catch {
        // O canal existe; um fetch falho só adia a atualização da lista.
      }
    }

    if (channel) chatState.rememberChannel(channel);

    // Conversa recém-criada não tem histórico nem fixada; semear os dois evita dois
    // requests que voltariam vazios.
    if (payload.meta?.created) {
      chatState.seedEmptyChannel(channelId);
    }

    chatState.setActiveChannel(channelId);

    if (shouldUseChatDrawer()) {
      // Sem await: a gaveta pinta no primeiro frame e a lista de canais não é o que o
      // leitor veio ver.
      ChatDrawer.open().catch(() => {});
    } else {
      m.route.set(app.route('chat.channel', { id: channelId }));
    }
  } catch (e: any) {
    app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.user_controls.start_chat_failed'));
  }
}

function mountDrawer(): void {
  const attach = () => {
    if (document.getElementById('ramon-chat-drawer')) return;

    const node = document.createElement('div');
    node.id = 'ramon-chat-drawer';
    document.body.appendChild(node);

    m.mount(node, ChatDrawer);
  };

  if (document.body) {
    attach();
  } else {
    document.addEventListener('DOMContentLoaded', attach, { once: true });
  }
}

/**
 * Se o chat aparece. Exige conta: as rotas `/chat/*` respondem 404 a quem não tem
 * acesso, e o cliente concorda com o servidor.
 */
function canUseChat(): boolean {
  if (!app.forum.attribute<boolean>('canUseChat')) return false;
  if (!app.session.user) return false;

  // Respeita a desativação por usuário em /settings.
  return app.session.user.preferences()?.['ramon-chat.enabled'] !== false;
}

/** Evita um segundo loop quando os dois chamadores pedem um. */
let polling = false;

/**
 * Ligado quando o servidor responde 401 a um poll em segundo plano: a sessão
 * acabou (expirou ou saiu em outra aba). Continuar perguntando geraria o alerta de
 * "permissão negada" a cada tick; a próxima ação do leitor pedirá login.
 */
let sessionLost = false;

/**
 * Handler de erro dos requests que o poller faz por conta própria. `.catch()` não
 * basta: `Application.requestErrorCatch` mostra o alerta antes de rejeitar, e este
 * hook é o único ponto de interceptação. 401 encerra o polling; 403 e 404 querem
 * dizer que o canal ou tópico não é mais visível, o que o websocket ou a próxima
 * navegação resolve. O resto vai para o handler padrão.
 */
const backgroundErrorHandler = (error: { status?: number }): false | void => {
  if (error?.status === 401) {
    if (!sessionLost) {
      sessionLost = true;
      console.warn('[ramon-chat] session ended; live updates stopped');
    }

    return;
  }

  if (error?.status === 403 || error?.status === 404) return;

  return false;
};

/**
 * Atualiza a lista de canais e o fim do canal aberto em intervalos. Roda sempre,
 * no ritmo do que o websocket comprovou (ver as constantes POLL_INTERVAL_*): um
 * socket inscrito que nunca entrega parece saudável daqui.
 *
 * Timeout encadeado em vez de setInterval para o ritmo mudar na sessão. Só roda com
 * o documento visível.
 */
function startPolling(): void {
  if (polling) return;

  polling = true;
  schedulePoll();
}

let pollTimer: number | null = null;

function schedulePoll(): void {
  pollTimer = window.setTimeout(() => {
    poll();
    schedulePoll();
  }, pollInterval());
}

/**
 * Reinicia a contagem no ritmo atual. Chamado quando o socket cai ou volta, para o
 * cliente desconectado não esperar um timeout agendado enquanto estava online.
 */
function reschedulePolling(): void {
  if (!polling) return;

  if (pollTimer !== null) window.clearTimeout(pollTimer);

  schedulePoll();
}

/** Tempo até o próximo poll, lido a cada tick para o ritmo mudar sem reiniciar nada. */
function pollInterval(): number {
  if (!realtimeBound()) return POLL_INTERVAL;

  if (realtimeLive()) return POLL_INTERVAL_PROVEN;

  return realtimeDelivered() ? POLL_INTERVAL : POLL_INTERVAL_UNPROVEN;
}

/** Quando a lista de canais foi atualizada pela última vez; ver CHANNEL_POLL_INTERVAL. */
let lastChannelPoll = 0;

function poll(): void {
  if (document.hidden) return;
  if (sessionLost) return;
  if (!chatState.channelsLoaded) return;

  const now = Date.now();
  const channelFloor = realtimeLive() ? CHANNEL_POLL_INTERVAL_PROVEN : CHANNEL_POLL_INTERVAL;

  if (now - lastChannelPoll >= channelFloor) {
    lastChannelPoll = now;

    // Forçado: a lista fica em cache na sessão para a navegação não refazer o pedido,
    // e o poller é justamente quem a mantém atual sem websocket.
    chatState.loadChannels(true, backgroundErrorHandler).catch(() => {});
  }

  const activeId = chatState.activeChannelId;

  if (activeId === null) return;

  const stream = chatState.streams[activeId];

  if (!stream || stream.loading) return;

  // Só o que for mais novo que a última mensagem conhecida.
  const newest = stream.messages[stream.messages.length - 1];

  app.store
    .find<Message[]>(
      'chat-messages',
      {
        filter: {
          channel: activeId,
          ...(newest ? { greaterThan: Number(newest.id()) } : {}),
        },
        sort: 'id',
        page: { limit: 50 },
      },
      undefined,
      { errorHandler: backgroundErrorHandler }
    )
    .then((results) => {
      for (const message of (Array.isArray(results) ? results : []) as Message[]) {
        chatState.upsertMessage(message);
      }

      m.redraw();
    })
    .catch(() => {});

  pollChanges(`channel:${activeId}`, { channel: activeId }, stream.messages);

  // O painel de tópico renderiza do próprio stream, e o filtro do canal exclui
  // respostas de tópico; sem isto o tópico aberto nunca seria consultado.
  const threadId = chatState.activeThreadId;

  if (threadId === null) return;

  const threadStream = chatState.threadStreams[threadId];

  if (!threadStream || threadStream.loading) return;

  const newestReply = threadStream.messages[threadStream.messages.length - 1];

  app.store
    .find<Message[]>(
      'chat-messages',
      {
        filter: {
          thread: threadId,
          ...(newestReply ? { greaterThan: Number(newestReply.id()) } : {}),
        },
        sort: 'id',
        page: { limit: 50 },
      },
      undefined,
      { errorHandler: backgroundErrorHandler }
    )
    .then((results) => {
      for (const message of (Array.isArray(results) ? results : []) as Message[]) {
        chatState.upsertMessage(message);
      }

      m.redraw();
    })
    .catch(() => {});

  pollChanges(`thread:${threadId}`, { thread: threadId }, threadStream.messages);
}

/**
 * Até onde avançou o cursor de mudanças de cada stream. Guardado aqui, não derivado
 * da janela carregada: a edição de uma mensagem fora da janela nunca entra nela, e
 * o cursor recalculado pediria a mesma edição a cada poll.
 */
const changeCursors = new Map<string, number>();

/**
 * Relê as mensagens de um stream que mudaram (reação, edição, exclusão, fixação),
 * que os polls por id mais novo não veem. Com websocket isso vem dos handlers de
 * realtime.ts.
 *
 * Nada é inserido no stream: o store tem um registro por id e o atualiza no lugar,
 * e o stream guarda esses mesmos objetos. Inserir encaixaria uma mensagem de fora
 * da janela no meio dela.
 */
function pollChanges(key: string, filter: Record<string, unknown>, messages: Message[]): void {
  const since = changeCursors.get(key) ?? newestChange(messages);

  if (since === null) return;

  app.store
    .find<Message[]>(
      'chat-messages',
      {
        filter: { ...filter, updatedSince: since },
        sort: 'id',
        page: { limit: 50 },
      },
      undefined,
      { errorHandler: backgroundErrorHandler }
    )
    .then((results) => {
      const advanced = newestChange((Array.isArray(results) ? results : []) as Message[]);

      // Só avança. Uma página cortada no limite pode voltar com máximo menor que o
      // cursor, e recuar repetiria as mesmas linhas para sempre.
      if (advanced !== null && advanced > since) {
        changeCursors.set(key, advanced);
      } else {
        changeCursors.set(key, since);
      }

      m.redraw();
    })
    .catch(() => {});
}

/**
 * O `updatedAt` mais recente de um conjunto de mensagens, em unix timestamp. Valor
 * do servidor, não do relógio do navegador: um relógio adiantado pediria mudanças
 * de um momento que ainda não aconteceu.
 */
function newestChange(messages: Message[]): number | null {
  let newest = 0;

  for (const message of messages) {
    const at = message.updatedAt()?.getTime();

    if (at && at > newest) newest = at;
  }

  return newest > 0 ? Math.floor(newest / 1000) : null;
}
