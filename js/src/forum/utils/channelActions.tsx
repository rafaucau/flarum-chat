import app from 'flarum/forum/app';

import type Channel from '../../common/models/Channel';
import type ChatState from '../state/ChatState';
import { acceptInvitation, adoptChannelPayload, declineInvitation, invitationErrorText } from './invitations';
import { loadChannelFormModal } from './lazy';
import { loadChannelInfoModal } from './lazy';

/**
 * Uma ação do ator sobre o canal, a partir do header. Descritor em vez de controle
 * renderizado: o header do canal desenha botões de ícone e a gaveta os põe num menu
 * `⋯` com rótulo, e as duas superfícies usam o mesmo conjunto.
 */
export interface ChannelAction {
  key: string;
  icon: string;
  /** Já traduzido: é o title do botão numa superfície e o texto na outra. */
  label: string;
  /** Desenhado pressionado: o painel de fixadas é um toggle. */
  active?: boolean;
  loading?: boolean;
  onclick: () => void;
}

/**
 * Canais com join/leave em andamento, por id. No módulo porque o request dura mais
 * que o controle: o menu da gaveta fecha no clique. Por canal para dois canais
 * seguidos não se bloquearem. Só guarda ids durante o request.
 */
const inFlight = new Set<string>();

export interface ChannelActionOptions {
  /**
   * A superfície não tem rota própria (a gaveta). Ações que navegariam abrem no
   * lugar: o ChatPage fecha a gaveta ao chegar, tirando o leitor da janela.
   */
  embedded?: boolean;
}

/**
 * Tudo o que o ator pode fazer no canal, na ordem das duas superfícies. Cada item
 * depende de uma flag calculada no servidor (ver ChannelPolicy), então o controle
 * some em vez de ser recusado.
 */
export function channelActions(channel: Channel, state: ChatState, options: ChannelActionOptions = {}): ChannelAction[] {
  const trans = (key: string) => app.translator.trans(`ramon-chat.forum.channel.${key}`, {}, true) as string;

  const busy = inFlight.has(String(channel.id()));
  const actions: ChannelAction[] = [];

  // O formulário de configurações exige permissão de edição; o modal de info do
  // título qualquer membro abre.
  if (channel.canEdit() && channel.isCategory()) {
    actions.push({
      key: 'edit',
      icon: 'fas fa-pen-to-square',
      label: trans('edit'),
      onclick: () => app.modal.show(loadChannelFormModal, { channel }),
    });
  }

  actions.push({
    key: 'pinned',
    icon: 'fas fa-thumbtack',
    label: trans('pinned_messages'),
    active: state.showPinned,
    onclick: () => {
      state.togglePinned();
      m.redraw();
    },
  });

  // Tópicos do canal, quando houver. Na página é a seção de tópicos filtrada; na
  // gaveta é uma sobreposição, como a busca.
  if (channel.threadingEnabled()) {
    actions.push({
      key: 'threads',
      icon: 'fas fa-code-branch',
      label: trans('threads'),
      active: options.embedded ? state.showThreads : undefined,
      onclick: options.embedded
        ? () => {
            state.toggleThreads();
            m.redraw();
          }
        : () => m.route.set(app.route('chat.threads', { channel: channel.id() })),
    });
  }

  actions.push({
    key: 'search',
    icon: 'fas fa-magnifying-glass',
    label: trans('search_in_channel'),
    // Na gaveta é um toggle sobre a conversa, desenhado pressionado enquanto aberto.
    active: options.embedded ? state.showSearch : undefined,
    onclick: options.embedded
      ? () => {
          state.toggleSearch();
          m.redraw();
        }
      : () => m.route.set(app.route('chat.search', { channel: channel.id() })),
  });

  // Sair só para quem está no canal. Canal direto mantém o histórico, então sair
  // não é destrutivo.
  if (channel.isFollowing()) {
    actions.push({
      key: 'leave',
      icon: 'fas fa-arrow-right-from-bracket',
      label: trans('leave'),
      loading: busy,
      onclick: () => void leaveChannel(channel, state),
    });

    return actions;
  }

  // Convite aberto: aceitar é entrar, então toma o lugar do join.
  if (channel.isInvited()) {
    actions.push({
      key: 'acceptInvite',
      icon: 'fas fa-check',
      label: trans('accept_invite'),
      loading: busy,
      onclick: () => void answerInvitation(channel, state, true),
    });

    actions.push({
      key: 'declineInvite',
      icon: 'fas fa-xmark',
      label: trans('decline_invite'),
      loading: busy,
      onclick: () => void answerInvitation(channel, state, false),
    });

    return actions;
  }

  // Voltar a um canal que você deixou. Duas entradas: o join comum entra na lista de
  // membros e na contagem, o oculto não, para um moderador observar sem mudar o
  // ambiente. O oculto só aparece se o servidor diz que o ator pode.
  if (channel.canJoin()) {
    actions.push({
      key: 'join',
      icon: 'fas fa-arrow-right-to-bracket',
      label: trans('join'),
      loading: busy,
      onclick: () => void joinChannel(channel, state, false),
    });
  }

  if (channel.canJoinHidden()) {
    actions.push({
      key: 'joinHidden',
      icon: 'fas fa-user-secret',
      label: trans('join_hidden'),
      loading: busy,
      onclick: () => void joinChannel(channel, state, true),
    });
  }

  return actions;
}

/**
 * Detalhes do canal: nível de notificação, membros e ações de estado permitidas.
 * Disponível a todo membro, ao contrário do formulário de configurações.
 */
export function openChannelInfo(channel: Channel): void {
  app.modal.show(loadChannelInfoModal, { channel });
}

async function joinChannel(channel: Channel, state: ChatState, hidden: boolean): Promise<void> {
  const id = String(channel.id());

  if (inFlight.has(id)) return;

  inFlight.add(id);
  m.redraw();

  try {
    const payload = await app.request<any>({
      method: 'POST',
      url: `${app.forum.attribute('apiUrl')}/chat-channels/${id}/join`,
      body: { data: { attributes: { hidden } } },
    });

    // O endpoint devolve o canal como o membro o vê agora, com as flags; colocá-lo no
    // store faz o composer aparecer sem recarregar.
    if (!adoptChannelPayload(payload)) {
      channel.pushAttributes({
        isFollowing: true,
        isHiddenMember: hidden,
        canPostMessage: channel.isOpen(),
        // Join oculto não entra na contagem.
        userCount: hidden ? channel.userCount() : (channel.userCount() ?? 0) + 1,
      });
    }

    state.rememberChannel(channel);

    if (hidden) {
      app.alerts.show({ type: 'success' }, app.translator.trans('ramon-chat.forum.channel.joined_hidden'));
    }
  } catch (e: any) {
    app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.channel.join_failed'));
  } finally {
    inFlight.delete(id);
    m.redraw();
  }
}

/**
 * Responde a um convite pelos controles do canal. Aceitar não navega, só faz o
 * composer aparecer; recusar num canal público deixa o leitor onde está.
 */
async function answerInvitation(channel: Channel, state: ChatState, accept: boolean): Promise<void> {
  const id = String(channel.id());

  if (inFlight.has(id)) return;

  if (!accept && !confirm(app.translator.trans('ramon-chat.forum.channel.decline_invite_confirm', {}, true))) {
    return;
  }

  inFlight.add(id);
  m.redraw();

  try {
    if (accept) {
      const joined = await acceptInvitation(Number(channel.id()));

      if (joined) state.rememberChannel(joined);

      app.alerts.show({ type: 'success' }, app.translator.trans('ramon-chat.forum.channel.invite_accepted'));
    } else {
      await declineInvitation(Number(channel.id()));

      app.alerts.show({ type: 'success' }, app.translator.trans('ramon-chat.forum.channel.invite_declined'));
    }
  } catch (e: any) {
    app.alerts.show({ type: 'error' }, invitationErrorText(e, 'ramon-chat.forum.channel.invite_failed'));
  } finally {
    inFlight.delete(id);
    m.redraw();
  }
}

/**
 * Sai do canal. A linha de membro fica no servidor, então leitura e histórico
 * sobrevivem a uma volta, e num canal direto a conversa retomada liga às mensagens
 * anteriores.
 */
async function leaveChannel(channel: Channel, state: ChatState): Promise<void> {
  const id = String(channel.id());

  if (inFlight.has(id)) return;

  if (!confirm(app.translator.trans('ramon-chat.forum.channel.leave_confirm', {}, true))) {
    return;
  }

  inFlight.add(id);
  m.redraw();

  try {
    await app.request({
      method: 'POST',
      url: `${app.forum.attribute('apiUrl')}/chat-channels/${id}/leave`,
    });

    channel.pushAttributes({
      isFollowing: false,
      unreadCount: 0,
      unreadMentionsCount: 0,
      userCount: Math.max(0, (channel.userCount() ?? 1) - 1),
    });

    state.channels = state.channels.filter((c) => c.id() !== channel.id());
    state.setActiveChannel(null);

    if ((m.route.get() ?? '').includes('/chat/c/')) {
      m.route.set(app.route('chat.index'));
    }
  } catch (e: any) {
    app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.channel.leave_failed'));
  } finally {
    inFlight.delete(id);
    m.redraw();
  }
}
