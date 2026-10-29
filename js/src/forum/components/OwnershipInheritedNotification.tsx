import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';

import { channelIdOf, channelNameOf, fromName, openChannelFromNotification } from './ModeratorPromotedNotification';

/**
 * "X saiu de #canal e agora você é o dono."
 *
 * Vai para o moderador mais antigo quando o dono sai (Service\OwnershipSuccession).
 * Ninguém perguntou a ele, então precisa ser avisado.
 */
export default class OwnershipInheritedNotification extends Notification {
  icon(): string {
    return 'fas fa-crown';
  }

  href(): string {
    const channelId = channelIdOf(this);

    return channelId ? app.route('chat.channel', { id: channelId }) : app.route('chat.index');
  }

  content(): Mithril.Children {
    return app.translator.trans('ramon-chat.forum.notifications.ownership_inherited', {
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
