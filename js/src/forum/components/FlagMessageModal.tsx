import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type Message from '../../common/models/Message';
import { messagePreview } from '../../common/utils/preview';
import { authorName } from '../utils/bot';

export interface FlagMessageModalAttrs extends IFormModalAttrs {
  message: Message;
}

/**
 * Motivos aceitos pelo servidor, na ordem exibida. Espelha `MessageFlag::REASONS`.
 * `other` exige explicação.
 */
const REASONS = ['spam', 'inappropriate', 'harassment', 'off_topic', 'other'] as const;

type Reason = (typeof REASONS)[number];

/**
 * Denuncia uma mensagem aos moderadores.
 *
 * Estende FormModal, não Modal: `Modal.wrapper()` devolve um fragment sem form, e
 * o onsubmit nunca dispararia.
 */
export default class FlagMessageModal extends FormModal<FlagMessageModalAttrs> {
  private reason!: Stream<Reason>;
  private detail!: Stream<string>;

  oninit(vnode: Mithril.Vnode<FlagMessageModalAttrs>): void {
    super.oninit(vnode);

    this.reason = Stream<Reason>('spam');
    this.detail = Stream('');
  }

  className(): string {
    return 'ChatModal ChatFlagModal Modal--small';
  }

  title(): Mithril.Children {
    return app.translator.trans('ramon-chat.forum.flag.title');
  }

  content(): Mithril.Children {
    const message = this.attrs.message;

    return (
      <div className="Modal-body">
        <div className="Form">
          {/* Texto puro: renderizar o HTML traria o conteúdo denunciado para o diálogo. */}
          <div className="ChatFlagModal-target">
            <div className="ChatFlagModal-target-author">{authorName(message)}</div>
            <div className="ChatFlagModal-target-excerpt">{messagePreview(message, 200)}</div>
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('ramon-chat.forum.flag.reason')}</label>

            <div className="ChatFlagModal-reasons">
              {REASONS.map((reason) => (
                <button
                  key={reason}
                  type="button"
                  className={classList('ChatFlagModal-reason', {
                    'ChatFlagModal-reason--active': this.reason() === reason,
                  })}
                  disabled={this.loading}
                  onclick={() => this.reason(reason)}
                >
                  {app.translator.trans(`ramon-chat.forum.flag.reasons.${reason}`)}
                </button>
              ))}
            </div>
          </div>

          <div className="Form-group">
            <label>
              {app.translator.trans(this.reason() === 'other' ? 'ramon-chat.forum.flag.detail_required' : 'ramon-chat.forum.flag.detail')}
            </label>

            <textarea
              className="FormControl"
              rows={3}
              maxlength={1000}
              bidi={this.detail}
              disabled={this.loading}
              placeholder={app.translator.trans('ramon-chat.forum.flag.detail_placeholder', {}, true)}
            />
          </div>

          <div className="Form-group">
            <Button
              className="Button Button--primary Button--block"
              type="submit"
              loading={this.loading}
              disabled={this.reason() === 'other' && this.detail().trim() === ''}
            >
              {app.translator.trans('ramon-chat.forum.flag.submit')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  /**
   * Ninguém aguarda este método; toda falha é tratada aqui para não virar
   * unhandled rejection.
   */
  onsubmit(e: SubmitEvent): void {
    e.preventDefault();

    if (this.loading) return;

    const detail = this.detail().trim();

    // O Enter envia o form mesmo com o botão desabilitado.
    if (this.reason() === 'other' && detail === '') return;

    this.loading = true;

    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-message-flags`,
        body: {
          data: {
            attributes: {
              messageId: Number(this.attrs.message.id()),
              reason: this.reason(),
              detail: detail || null,
            },
          },
        },
      })
      .then(() => {
        // Local, para o botão mostrar "denunciada" sem esperar o próximo fetch.
        this.attrs.message.pushAttributes({ isFlagged: true });

        this.hide();

        app.alerts.show({ type: 'success' }, app.translator.trans('ramon-chat.forum.flag.sent'));
      })
      .catch((e: any) => {
        this.loading = false;

        app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.flag.failed'));

        m.redraw();
      });
  }
}
