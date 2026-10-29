import ChatState from './ChatState';

/**
 * Estado global do chat. Singleton para que a gaveta, a página e os handlers de
 * realtime vejam os mesmos canais e marcadores de leitura.
 *
 * Fica numa const nomeada antes de exportar: o `autoExportLoader` do Flarum
 * reescreve a exportação padrão procurando a palavra-chave no fonte, e só aceita
 * um identificador simples ali (nem a frase pode aparecer num comentário).
 */
const chatState = new ChatState();

export default chatState;
