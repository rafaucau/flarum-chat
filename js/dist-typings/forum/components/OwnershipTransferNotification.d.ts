import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
/**
 * "X quer transferir #canal para você", com os botões de resposta na linha.
 *
 * Como em ChannelInviteNotification, responde-se no sino ou no toast do
 * flarum/realtime. A linha sai do sino quando a oferta é respondida ou retirada.
 */
export default class OwnershipTransferNotification extends Notification {
    private answered;
    private busy;
    icon(): string;
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    onclick(e: MouseEvent): void;
    /**
     * Os botões ficam dentro do link da linha; sem parar o evento, recusar também
     * abriria o canal.
     */
    protected answer(e: MouseEvent, accept: boolean): Promise<void>;
}
