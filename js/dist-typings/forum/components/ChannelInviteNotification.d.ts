import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
interface InviteData {
    channelId?: number;
    channelName?: string;
    isPrivate?: boolean;
}
/**
 * "X convidou você para #canal", com os botões de resposta na linha.
 *
 * Canal privado só fica visível após aceitar, então a resposta precisa estar aqui
 * (no sino e no toast do flarum/realtime). Aceitar abre o canal no drawer ou na
 * página, conforme a preferência.
 */
export default class ChannelInviteNotification extends Notification {
    /** Resposta já dada nesta linha; substitui os botões. */
    private answered;
    private busy;
    icon(): string;
    /** Canal privado não abre antes de aceitar; a linha leva ao chat, não a um 404. */
    href(): string;
    content(): Mithril.Children;
    excerpt(): Mithril.Children;
    /**
     * Abre o drawer em vez de seguir o href quando essa é a preferência do usuário,
     * igual ao botão do cabeçalho.
     */
    onclick(e: MouseEvent): void;
    /**
     * Os botões ficam dentro do link da linha; sem parar o evento, recusar também
     * abriria o canal.
     */
    protected answer(e: MouseEvent, accept: boolean): Promise<void>;
    /** Leva o novo membro ao canal, no drawer ou na página. */
    protected open(channelId: number): void;
    protected channelId(): number | null;
    /**
     * Lido dos dados da notificação, não do canal: canal privado só carrega para
     * membros, por isso o nome é gravado na notificação.
     */
    protected channelName(): string;
    protected data(): InviteData;
}
export {};
