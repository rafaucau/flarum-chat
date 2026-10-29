import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

import chatState from '../state/chat';
import ChatDrawer from './ChatDrawer';
import { shouldUseChatDrawer } from '../utils/surface';
import { acceptInvitation, declineInvitation, invitationErrorText } from '../utils/invitations';

interface InviteData {
  channelId?: number;
  channelName?: string;
  isPrivate?: boolean;
}

/**
 * "X convidou você para #canal", com os botões de resposta na linha.
 *
 * Canal privado só fica visível após aceitar, então a resposta precisa estar aqui
 * (no sino e no toast do flarum/realtime). Aceitar abre o canal no drawer ou na
 * página, conforme a preferência.
 */
export default class ChannelInviteNotification extends Notification {
  /** Resposta já dada nesta linha; substitui os botões. */
  private answered: 'accepted' | 'declined' | null = null;

  private busy: 'accept' | 'decline' | null = null;

  icon(): string {
    return 'fas fa-envelope-open';
  }

  /** Canal privado não abre antes de aceitar; a linha leva ao chat, não a um 404. */
  href(): string {
    const channelId = this.channelId();

    return channelId && (!this.data().isPrivate || this.answered === 'accepted')
      ? app.route('chat.channel', { id: channelId })
      : app.route('chat.index');
  }

  content(): Mithril.Children {
    const inviter = this.attrs.notification.fromUser();

    return app.translator.trans('ramon-chat.forum.notifications.channel_invite', {
      username: inviter ? inviter.displayName() : app.translator.trans('ramon-chat.forum.notifications.someone', {}, true),
      channel: this.channelName(),
    });
  }

  excerpt(): Mithril.Children {
    if (this.answered) {
      return app.translator.trans(
        this.answered === 'accepted' ? 'ramon-chat.forum.channel.invite_accepted' : 'ramon-chat.forum.channel.invite_declined'
      );
    }

    // Canal já na sidebar: respondido em outro lugar; um segundo aceite seria recusado.
    const channelId = this.channelId();
    const known = channelId ? chatState.channel(channelId) : null;

    if (known?.isFollowing()) {
      return app.translator.trans('ramon-chat.forum.channel.invite_accepted');
    }

    return (
      <span className="ChatInviteActions">
        {this.data().isPrivate ? (
          <span className="ChatInviteActions-hint">
            <i className="fas fa-lock" aria-hidden="true" />
            {app.translator.trans('ramon-chat.forum.new_channel.private')}
          </span>
        ) : null}

        <Button
          className="Button Button--primary Button--compact"
          icon="fas fa-check"
          loading={this.busy === 'accept'}
          disabled={this.busy !== null}
          onclick={(e: MouseEvent) => this.answer(e, true)}
        >
          {app.translator.trans('ramon-chat.forum.notifications.invite_accept')}
        </Button>

        <Button
          className="Button Button--compact"
          icon="fas fa-xmark"
          loading={this.busy === 'decline'}
          disabled={this.busy !== null}
          onclick={(e: MouseEvent) => this.answer(e, false)}
        >
          {app.translator.trans('ramon-chat.forum.notifications.invite_decline')}
        </Button>
      </span>
    );
  }

  /**
   * Abre o drawer em vez de seguir o href quando essa é a preferência do usuário,
   * igual ao botão do cabeçalho.
   */
  onclick(e: MouseEvent): void {
    const channelId = this.channelId();

    if (!channelId) return;

    if (this.data().isPrivate && this.answered !== 'accepted') return;

    // Sem drawer, segue o href; inclui o drawer do próprio Flarum, onde a lista de
    // notificações é uma página.
    if (!shouldUseChatDrawer()) return;

    e.preventDefault();

    chatState.setActiveChannel(channelId);
    ChatDrawer.open();
  }

  /**
   * Os botões ficam dentro do link da linha; sem parar o evento, recusar também
   * abriria o canal.
   */
  protected async answer(e: MouseEvent, accept: boolean): Promise<void> {
    e.preventDefault();
    e.stopPropagation();

    const channelId = this.channelId();

    if (!channelId || this.busy) return;

    this.busy = accept ? 'accept' : 'decline';
    m.redraw();

    try {
      if (accept) {
        await acceptInvitation(channelId);
        this.answered = 'accepted';
      } else {
        await declineInvitation(channelId);
        this.answered = 'declined';
      }

      this.markAsRead();
    } catch (error: any) {
      // 404: o convite já foi respondido em outra aba ou retirado.
      if (error?.status === 404) {
        this.answered = 'declined';
      }

      app.alerts.show({ type: 'error' }, invitationErrorText(error, 'ramon-chat.forum.channel.invite_failed'));
    } finally {
      this.busy = null;
      m.redraw();
    }

    if (this.answered === 'accepted') this.open(channelId);
  }

  /** Leva o novo membro ao canal, no drawer ou na página. */
  protected open(channelId: number): void {
    chatState.setActiveChannel(channelId);

    if (shouldUseChatDrawer()) {
      ChatDrawer.open();

      return;
    }

    m.route.set(app.route('chat.channel', { id: channelId }));
  }

  protected channelId(): number | null {
    const id = this.data().channelId;

    return id ? Number(id) : null;
  }

  /**
   * Lido dos dados da notificação, não do canal: canal privado só carrega para
   * membros, por isso o nome é gravado na notificação.
   */
  protected channelName(): string {
    return this.data().channelName ?? (app.translator.trans('ramon-chat.forum.nav.chat', {}, true) as string);
  }

  protected data(): InviteData {
    return (this.attrs.notification.content() as InviteData | null) ?? {};
  }
}
