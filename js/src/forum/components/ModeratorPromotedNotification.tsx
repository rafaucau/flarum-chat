import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import chatState from '../state/chat';
import ChatDrawer from './ChatDrawer';
import { shouldUseChatDrawer } from '../utils/surface';

interface PromotedData {
  channelId?: number;
}

/**
 * "X tornou você moderador de #canal."
 *
 * Os dados guardados são só ids; o nome vem do subject, que a lista de
 * notificações carrega pelas regras de visibilidade do canal.
 */
export default class ModeratorPromotedNotification extends Notification {
  icon(): string {
    return 'fas fa-user-shield';
  }

  href(): string {
    const channelId = channelIdOf(this);

    return channelId ? app.route('chat.channel', { id: channelId }) : app.route('chat.index');
  }

  content(): Mithril.Children {
    return app.translator.trans('ramon-chat.forum.notifications.moderator_promoted', {
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

/** Id do canal, vindo dos dados ou do subject. */
export function channelIdOf(notification: Notification): number | null {
  const data = (notification.attrs.notification.content() as PromotedData | null) ?? {};
  const subject = notification.attrs.notification.subject() as Channel | null;
  const id = data.channelId ?? (subject ? Number(subject.id()) : null);

  return id ? Number(id) : null;
}

/** Nome do canal como o leitor o vê, ou o título do chat. */
export function channelNameOf(notification: Notification): string {
  const subject = notification.attrs.notification.subject() as Channel | null;

  return subject?.displayName?.() || (app.translator.trans('ramon-chat.forum.nav.chat', {}, true) as string);
}

/** Quem enviou a notificação, ou "Alguém". */
export function fromName(notification: Notification): string {
  const user = notification.attrs.notification.fromUser();

  return user ? user.displayName() : (app.translator.trans('ramon-chat.forum.notifications.someone', {}, true) as string);
}

/**
 * Abre o drawer em vez de seguir o href quando essa é a preferência do leitor,
 * igual ao botão do cabeçalho.
 */
export function openChannelFromNotification(notification: Notification, e: MouseEvent): void {
  const channelId = channelIdOf(notification);

  if (!channelId || !shouldUseChatDrawer()) return;

  e.preventDefault();

  chatState.setActiveChannel(channelId);
  ChatDrawer.open();
}
