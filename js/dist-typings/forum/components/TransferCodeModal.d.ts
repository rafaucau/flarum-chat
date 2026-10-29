import FormModal from 'flarum/common/components/FormModal';
import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
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
    private code;
    oninit(vnode: Mithril.Vnode<TransferCodeModalAttrs>): void;
    className(): string;
    title(): Mithril.Children;
    content(): Mithril.Children;
    onready(): void;
    onsubmit(e: SubmitEvent): void;
}
