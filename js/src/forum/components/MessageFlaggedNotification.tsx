import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';

interface FlaggedData {
  flagId?: number;
  messageId?: number;
  channelId?: number;
  channelName?: string;
  reason?: string;
}

/**
 * "X denunciou uma mensagem em #canal." Vai para os moderadores.
 *
 * O clique leva à fila, e não à mensagem: lá estão o texto da denúncia e as ações.
 */
export default class MessageFlaggedNotification extends Notification {
  icon(): string {
    return 'fas fa-flag';
  }

  href(): string {
    return app.route('chat.flags');
  }

  content(): Mithril.Children {
    const reporter = this.attrs.notification.fromUser();

    return app.translator.trans('ramon-chat.forum.notifications.message_flagged', {
      username: reporter ? reporter.displayName() : app.translator.trans('ramon-chat.forum.notifications.someone', {}, true),
      channel: this.data().channelName ?? '',
    });
  }

  /**
   * Mostra o motivo, não o texto denunciado: os dados da notificação voltam sem
   * nova checagem de permissão, e uma mensagem ocultada depois ainda apareceria
   * aqui (ver MessageFlaggedBlueprint).
   */
  excerpt(): Mithril.Children {
    const reason = this.data().reason;

    if (!reason) return null;

    return app.translator.trans(`ramon-chat.forum.flag.reasons.${reason}`);
  }

  protected data(): FlaggedData {
    return (this.attrs.notification.content() as FlaggedData | null) ?? {};
  }
}
