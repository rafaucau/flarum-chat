import app from "flarum/forum/app";
import haptic from "flarum/common/utils/haptic";
import type { IFormModalAttrs } from "flarum/common/components/FormModal";
import type Mithril from "mithril";
import type FlagPostModal from "ext:flarum/flags/forum/components/FlagPostModal";

import type Message from "../../common/models/Message";

export interface ChatFlagPostModalAttrs extends IFormModalAttrs {
  message: Message;
}

/**
 * flarum/flags' own report dialog, filing against a chat message.
 *
 * Built from the class at runtime because it belongs to an extension the chat
 * does not depend on. Its reasons, wording, guidelines link and confirmation are
 * flarum/flags', so a member reports a message the way they report a post. Only
 * the request differs: a chat message is not a post, so the report goes to the
 * chat's own queue. Every reason that dialog offers is one the server accepts.
 */
export default function chatFlagPostModal(
  Base: typeof FlagPostModal,
): typeof FlagPostModal<ChatFlagPostModalAttrs> {
  return class ChatFlagPostModal extends Base<ChatFlagPostModalAttrs> {
    title(): Mithril.Children {
      return app.translator.trans("ramon-chat.forum.flag.title");
    }

    onsubmit(e: SubmitEvent): void {
      e.preventDefault();

      haptic("warning");
      this.loading = true;

      const message = this.attrs.message;
      const detail = this.reasonDetail().trim();

      app
        .request({
          method: "POST",
          url: `${app.forum.attribute("apiUrl")}/chat-message-flags`,
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
