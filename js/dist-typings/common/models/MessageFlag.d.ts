import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Message from './Message';
/**
 * Denúncia de uma mensagem do chat. Não usa o flarum/flags porque lá
 * `flags.post_id` é uma FK obrigatória para `posts`.
 */
export default class MessageFlag extends Model {
    /** Uma das chaves de MessageFlag::REASONS no servidor. */
    reason: () => string;
    /** Texto do denunciante; sempre exibido como texto, nunca como HTML. */
    detail: () => string | null;
    messageId: () => number;
    createdAt: () => Date | null | undefined;
    resolvedAt: () => Date | null | undefined;
    isResolved: () => boolean;
    /** Null se o denunciante apagou a conta; a denúncia continua. */
    user: () => false | User | null;
    message: () => false | Message | null;
    resolvedBy: () => false | User | null;
    apiEndpoint(): string;
}
