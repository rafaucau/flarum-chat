import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import type { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

export interface MessageTooLongModalAttrs extends IInternalModalAttrs {
  /** Tamanho do que o remetente tentou enviar. */
  length: number;
  /** Limite que vale neste canal. */
  max: number;
}

/**
 * Avisa que a mensagem passou do limite e por quanto.
 *
 * Modal porque o envio acabou de ser recusado e o composer manteve o texto.
 * Não se usa `maxlength` no textarea: o navegador cortaria o fim do que foi colado
 * sem avisar.
 */
export default class MessageTooLongModal extends Modal<MessageTooLongModalAttrs> {
  className(): string {
    return 'ChatModal ChatTooLongModal Modal--small';
  }

  title(): Mithril.Children {
    return app.translator.trans('ramon-chat.forum.composer.too_long_title');
  }

  content(): Mithril.Children {
    const { length, max } = this.attrs;

    return (
      <div className="Modal-body ChatTooLongModal-body">
        <p className="ChatTooLongModal-text">
          {app.translator.trans('ramon-chat.forum.composer.too_long_body', {
            over: length - max,
            max,
          })}
        </p>

        <Button className="Button Button--primary Button--block" onclick={() => this.hide()}>
          {app.translator.trans('ramon-chat.forum.composer.too_long_dismiss')}
        </Button>
      </div>
    );
  }
}
