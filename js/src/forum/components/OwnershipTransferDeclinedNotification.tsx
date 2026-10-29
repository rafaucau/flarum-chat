import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';

import { channelIdOf, channelNameOf, fromName, openChannelFromNotification } from './ModeratorPromotedNotification';

/**
 * "X recusou assumir #canal." Vai para quem ofereceu o canal, para a recusa
 * chegar como resposta em vez de a oferta só deixar de estar pendente.
 */
export default class OwnershipTransferDeclinedNotification extends Notification {
  icon(): string {
    return 'fas fa-user-xmark';
  }

  href(): string {
    const channelId = channelIdOf(this);

    return channelId ? app.route('chat.channel', { id: channelId }) : app.route('chat.index');
  }

  content(): Mithril.Children {
    return app.translator.trans('ramon-chat.forum.notifications.ownership_transfer_declined', {
      username: fromName(this),
      channel: channelNameOf(this),
    });
  }

  excerpt(): Mithril.Children {
    return null;
  }

  onclick(e: MouseEvent): void {
    openChannelFromNotification(this, e);
  }
}
