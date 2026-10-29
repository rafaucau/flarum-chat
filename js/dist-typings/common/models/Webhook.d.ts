import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Channel from './Channel';
export default class Webhook extends Model {
    name: () => string;
    description: () => string | null;
    /** Sobrescrevem como a mensagem entregue é atribuída no stream. */
    username: () => string | null;
    emoji: () => string | null;
    active: () => boolean;
    channelId: () => number;
    deliveriesCount: () => number;
    lastDeliveredAt: () => Date | null | undefined;
    createdAt: () => Date | null | undefined;
    /**
     * Só vem preenchida no create e no rotate; nas demais respostas é null, para
     * a chave não poder ser lida de uma listagem.
     */
    key: () => string | null;
    url: () => string | null;
    /** Conta de admin que assina as entregas; null usa o bot. */
    userId: () => number | null;
    channel: () => false | Channel | null;
    creator: () => false | User | null;
    user: () => false | User | null;
    apiEndpoint(): string;
}
