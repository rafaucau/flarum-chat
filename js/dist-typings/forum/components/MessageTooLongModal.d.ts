import Modal from 'flarum/common/components/Modal';
import type { IInternalModalAttrs } from 'flarum/common/components/Modal';
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
    className(): string;
    title(): Mithril.Children;
    content(): Mithril.Children;
}
