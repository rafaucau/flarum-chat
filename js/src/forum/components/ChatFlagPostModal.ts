import app from 'flarum/forum/app';
import haptic from 'flarum/common/utils/haptic';
import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
import type Mithril from 'mithril';
import type FlagPostModal from 'ext:flarum/flags/forum/components/FlagPostModal';

import type Message from '../../common/models/Message';

export interface ChatFlagPostModalAttrs extends IFormModalAttrs {
  message: Message;
}

/**
 * O diálogo de denúncia do flarum/flags, aplicado a uma mensagem do chat.
 *
 * Montado a partir da classe em runtime porque o chat não depende do flarum/flags.
 * Só a requisição muda: a denúncia vai para a fila do chat. O servidor aceita
 * todos os motivos que o diálogo oferece.
 */
export default function chatFlagPostModal(Base: typeof FlagPostModal): typeof FlagPostModal<ChatFlagPostModalAttrs> {
  return class ChatFlagPostModal extends Base<ChatFlagPostModalAttrs> {
    title(): Mithril.Children {
      return app.translator.trans('ramon-chat.forum.flag.title');
    }

    onsubmit(e: SubmitEvent): void {
      e.preventDefault();

      haptic('warning');
      this.loading = true;

      const message = this.attrs.message;
      const detail = this.reasonDetail().trim();

      app
        .request({
          method: 'POST',
          url: `${app.forum.attribute('apiUrl')}/chat-message-flags`,
          body: {
            data: {
              attributes: {
                messageId: Number(message.id()),
                reason: this.reason(),
                detail: detail || null,
              },
            },
          },
          errorHandler: this.onerror.bind(this),
        })
        .then(() => {
          message.pushAttributes({ isFlagged: true });
          this.success = true;
        })
        .catch(() => {})
        .then(() => this.loaded());
    }
  };
}
