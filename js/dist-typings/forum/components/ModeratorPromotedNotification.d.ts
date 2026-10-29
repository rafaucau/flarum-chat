import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
/**
 * "X tornou você moderador de #canal."
 *
 * Os dados guardados são só ids; o nome vem do subject, que a lista de
 * notificações carrega pelas regras de visibilidade do canal.
 */
export default class ModeratorPromotedNotification extends Notification {
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    onclick(e: MouseEvent): void;
}
/** Id do canal, vindo dos dados ou do subject. */
export declare function channelIdOf(notification: Notification): number | null;
/** Nome do canal como o leitor o vê, ou o título do chat. */
export declare function channelNameOf(notification: Notification): string;
/** Quem enviou a notificação, ou "Alguém". */
export declare function fromName(notification: Notification): string;
/**
 * Abre o drawer em vez de seguir o href quando essa é a preferência do leitor,
 * igual ao botão do cabeçalho.
 */
export declare function openChannelFromNotification(notification: Notification, e: MouseEvent): void;
