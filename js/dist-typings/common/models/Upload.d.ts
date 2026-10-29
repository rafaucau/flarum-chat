import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
export default class Upload extends Model {
    fileName: () => string;
    mimeType: () => string | null;
    size: () => number;
    /** Só em imagens, para reservar o espaço e evitar reflow ao carregar. */
    width: () => number | null;
    height: () => number | null;
    messageId: () => number | null;
    url: () => string;
    isImage: () => boolean;
    createdAt: () => Date | null | undefined;
    user: () => false | User | null;
    /** Pendente: anexado no composer, mensagem ainda não enviada. */
    isPending(): boolean;
    humanSize(): string;
    apiEndpoint(): string;
}
