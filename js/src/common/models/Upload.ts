import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';

export default class Upload extends Model {
  fileName = Model.attribute<string>('fileName');
  mimeType = Model.attribute<string | null>('mimeType');
  size = Model.attribute<number>('size');

  /** Só em imagens, para reservar o espaço e evitar reflow ao carregar. */
  width = Model.attribute<number | null>('width');
  height = Model.attribute<number | null>('height');

  messageId = Model.attribute<number | null>('messageId');
  url = Model.attribute<string>('url');
  isImage = Model.attribute<boolean>('isImage');
  createdAt = Model.attribute('createdAt', Model.transformDate);

  user = Model.hasOne<User | null>('user');

  /** Pendente: anexado no composer, mensagem ainda não enviada. */
  isPending(): boolean {
    return this.messageId() === null;
  }

  humanSize(): string {
    const bytes = this.size() ?? 0;
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
      value /= 1024;
      unit++;
    }

    return (unit === 0 ? value : value.toFixed(1)) + ' ' + units[unit];
  }

  apiEndpoint(): string {
    return '/chat-uploads' + (this.exists ? '/' + this.id() : '');
  }
}
