import FormModal from 'flarum/common/components/FormModal';
import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
import type Mithril from 'mithril';
import type Message from '../../common/models/Message';
export interface FlagMessageModalAttrs extends IFormModalAttrs {
    message: Message;
}
/**
 * Denuncia uma mensagem aos moderadores.
 *
 * Estende FormModal, não Modal: `Modal.wrapper()` devolve um fragment sem form, e
 * o onsubmit nunca dispararia.
 */
export default class FlagMessageModal extends FormModal<FlagMessageModalAttrs> {
    private reason;
    private detail;
    oninit(vnode: Mithril.Vnode<FlagMessageModalAttrs>): void;
    className(): string;
    title(): Mithril.Children;
    content(): Mithril.Children;
    /**
     * Ninguém aguarda este método; toda falha é tratada aqui para não virar
     * unhandled rejection.
     */
    onsubmit(e: SubmitEvent): void;
}
