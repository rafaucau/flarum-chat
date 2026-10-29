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
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    /**
     * Mostra o motivo, não o texto denunciado: os dados da notificação voltam sem
     * nova checagem de permissão, e uma mensagem ocultada depois ainda apareceria
     * aqui (ver MessageFlaggedBlueprint).
     */
    excerpt(): Mithril.Children;
    protected data(): FlaggedData;
}
export {};
