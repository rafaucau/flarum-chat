import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
interface DeclinedData {
    channelId?: number;
    channelName?: string;
    isPrivate?: boolean;
}
/**
 * "X recusou seu convite para #canal."
 *
 * Vai para o dono e para quem convidou, para a recusa chegar como resposta.
 * O clique abre o canal, de onde se convida outra pessoa pela aba de membros.
 */
export default class ChannelInviteDeclinedNotification extends Notification {
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    protected data(): DeclinedData;
}
export {};
