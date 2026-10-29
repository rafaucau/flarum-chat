import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
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
export default function chatFlagPostModal(Base: typeof FlagPostModal): typeof FlagPostModal<ChatFlagPostModalAttrs>;
