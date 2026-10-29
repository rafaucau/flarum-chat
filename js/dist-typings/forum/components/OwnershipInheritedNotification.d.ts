import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
/**
 * "X saiu de #canal e agora você é o dono."
 *
 * Vai para o moderador mais antigo quando o dono sai (Service\OwnershipSuccession).
 * Ninguém perguntou a ele, então precisa ser avisado.
 */
export default class OwnershipInheritedNotification extends Notification {
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    onclick(e: MouseEvent): void;
}
