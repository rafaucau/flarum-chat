import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import { confirmTransfer } from '../utils/transfers';
import { invitationErrorText } from '../utils/invitations';

export interface TransferCodeModalAttrs extends IFormModalAttrs {
  channel: Channel;
  /** Nome de quem recebe a oferta do canal. */
  recipientName: string;
  /** Recebe o canal devolvido pelo servidor após o código ser aceito. */
  onConfirmed?: (channel: Channel | null) => void;
}

/**
 * Segundo passo da transferência: o dono digita o código de 6 dígitos recebido
 * por e-mail. O quinto código errado encerra a transferência no servidor.
 * Estende FormModal para o Enter enviar.
 */
export default class TransferCodeModal extends FormModal<TransferCodeModalAttrs> {
  private code!: Stream<string>;

  oninit(vnode: Mithril.Vnode<TransferCodeModalAttrs>): void {
    super.oninit(vnode);

    this.code = Stream('');
  }

  className(): string {
    return 'ChatModal ChatTransferCodeModal Modal--small';
  }

  title(): Mithril.Children {
    return app.translator.trans('ramon-chat.forum.info.transfer_title');
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body">
        <div className="Form">
          <p className="helpText">
            {app.translator.trans('ramon-chat.forum.info.transfer_intro', {
              username: this.attrs.recipientName,
            })}
          </p>

          <div className="Form-group">
            <label for="chat-transfer-code">{app.translator.trans('ramon-chat.forum.info.transfer_code_label')}</label>

            <input
              id="chat-transfer-code"
              className="FormControl ChatTransferCodeModal-code"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength={6}
              pattern="[0-9]{6}"
              disabled={this.loading}
              value={this.code()}
              oninput={(e: Event) => this.code((e.target as HTMLInputElement).value.replace(/\D+/g, '').slice(0, 6))}
            />
          </div>

          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading} disabled={this.code().length !== 6}>
              {app.translator.trans('ramon-chat.forum.info.transfer_submit')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onready(): void {
    (this.element.querySelector('#chat-transfer-code') as HTMLInputElement | null)?.focus();
  }

  onsubmit(e: SubmitEvent): void {
    e.preventDefault();

    if (this.loading || this.code().length !== 6) return;

    this.loading = true;

    confirmTransfer(this.attrs.channel.id() as string, this.code())
      .then((channel) => {
        this.hide();

        app.alerts.show(
          { type: 'success' },
          app.translator.trans('ramon-chat.forum.info.transfer_offered', {
            username: this.attrs.recipientName,
          })
        );

        this.attrs.onConfirmed?.(channel);
      })
      .catch((error: any) => {
        this.loading = false;
        this.code('');

        app.alerts.show({ type: 'error' }, invitationErrorText(error, 'ramon-chat.forum.info.transfer_failed'));

        // A transferência pode ter acabado no servidor (quinto erro ou expirou);
        // quem chamou relê a aba de membros de qualquer forma.
        this.attrs.onConfirmed?.(null);

        m.redraw();
      });
  }
}
