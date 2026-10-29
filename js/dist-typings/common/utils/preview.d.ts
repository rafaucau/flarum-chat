import type Message from '../models/Message';
/**
 * Resumo de uma linha, em texto puro, para onde uma mensagem aparece dentro de
 * outra coisa (barra de fixadas, resposta, thread, busca, favoritos). Lê o HTML
 * renderizado, não o Markdown cru. Texto puro mantém o layout de uma linha e
 * nunca passa por `m.trust`.
 */
export declare function messagePreview(message: Message | null | undefined, limit?: number): string;
export default messagePreview;
