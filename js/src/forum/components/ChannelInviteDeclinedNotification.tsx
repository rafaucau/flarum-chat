import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';

interface DeclinedData {
  channelId?: number;
  channelName?: string;
  isPrivate?: boolean;
}

/**
 * "X recusou seu convite para #canal."
 *
 * Vai para o dono e para quem convidou, para a recusa chegar como resposta.
 * O clique abre o canal, de onde se convida outra pessoa pela aba de membros.
 */
export default class ChannelInviteDeclinedNotification extends Notification {
  icon(): string {
    return 'fas fa-user-xmark';
  }

  href(): string {
    const channelId = this.data().channelId;

    return channelId ? app.route('chat.channel', { id: channelId }) : app.route('chat.index');
  }

  content(): Mithril.Children {
    const decliner = this.attrs.notification.fromUser();

    return app.translator.trans('ramon-chat.forum.notifications.channel_invite_declined', {
      username: decliner ? decliner.displayName() : app.translator.trans('ramon-chat.forum.notifications.someone', {}, true),
      channel: this.data().channelName ?? app.translator.trans('ramon-chat.forum.nav.chat', {}, true),
    });
  }

  excerpt(): Mithril.Children {
    return null;
  }

  protected data(): DeclinedData {
    return (this.attrs.notification.content() as DeclinedData | null) ?? {};
  }
}
