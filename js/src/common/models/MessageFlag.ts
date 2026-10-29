import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';

import type Message from './Message';

/**
 * Denúncia de uma mensagem do chat. Não usa o flarum/flags porque lá
 * `flags.post_id` é uma FK obrigatória para `posts`.
 */
export default class MessageFlag extends Model {
  /** Uma das chaves de MessageFlag::REASONS no servidor. */
  reason = Model.attribute<string>('reason');

  /** Texto do denunciante; sempre exibido como texto, nunca como HTML. */
  detail = Model.attribute<string | null>('detail');

  messageId = Model.attribute<number>('messageId');

  createdAt = Model.attribute('createdAt', Model.transformDate);
  resolvedAt = Model.attribute('resolvedAt', Model.transformDate);
  isResolved = Model.attribute<boolean>('isResolved');

  /** Null se o denunciante apagou a conta; a denúncia continua. */
  user = Model.hasOne<User | null>('user');
  message = Model.hasOne<Message | null>('message');
  resolvedBy = Model.hasOne<User | null>('resolvedBy');

  apiEndpoint(): string {
    return '/chat-message-flags' + (this.exists ? '/' + this.id() : '');
  }
}
