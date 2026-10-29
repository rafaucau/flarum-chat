import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
/**
 * "X recusou assumir #canal." Vai para quem ofereceu o canal, para a recusa
 * chegar como resposta em vez de a oferta só deixar de estar pendente.
 */
export default class OwnershipTransferDeclinedNotification extends Notification {
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    onclick(e: MouseEvent): void;
}
