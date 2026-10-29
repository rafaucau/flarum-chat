import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Channel from './Channel';

export default class Webhook extends Model {
  name = Model.attribute<string>('name');
  description = Model.attribute<string | null>('description');

  /** Sobrescrevem como a mensagem entregue é atribuída no stream. */
  username = Model.attribute<string | null>('username');
  emoji = Model.attribute<string | null>('emoji');

  active = Model.attribute<boolean>('active');
  channelId = Model.attribute<number>('channelId');

  deliveriesCount = Model.attribute<number>('deliveriesCount');
  lastDeliveredAt = Model.attribute('lastDeliveredAt', Model.transformDate);
  createdAt = Model.attribute('createdAt', Model.transformDate);

  /**
   * Só vem preenchida no create e no rotate; nas demais respostas é null, para
   * a chave não poder ser lida de uma listagem.
   */
  key = Model.attribute<string | null>('key');
  url = Model.attribute<string | null>('url');

  /** Conta de admin que assina as entregas; null usa o bot. */
  userId = Model.attribute<number | null>('userId');

  channel = Model.hasOne<Channel | null>('channel');
  creator = Model.hasOne<User | null>('creator');
  user = Model.hasOne<User | null>('user');

  apiEndpoint(): string {
    return '/chat-webhooks' + (this.exists ? '/' + this.id() : '');
  }
}
