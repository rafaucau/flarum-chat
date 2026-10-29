export declare function setConnectionHandlers(handlers: {
    reconnect: () => void;
    change: () => void;
}): void;
/**
 * Se o socket está conectado e comprovou entrega de ponta a ponta: o único estado
 * em que o polling é puro desperdício.
 */
export declare function realtimeLive(): boolean;
/**
 * Liga os eventos do chat no canal privado do ator (ver ChatBroadcaster).
 *
 * Retorna se ligou. Tenta de novo por um tempo porque o realtime cria
 * `app.websocket` no próprio `mount`, e a ordem das extensões não é garantida; sem
 * isso, chegar milissegundos antes rebaixaria o cliente ao polling de vez.
 */
export declare function bindRealtime(): boolean;
export declare function setPollingFallback(fn: () => void): void;
/** Se os handlers do websocket estão ativos, para diagnóstico. */
export declare function realtimeBound(): boolean;
/**
 * Se algo já chegou pelo socket. Diferente de `bound`: a inscrição funciona no
 * cliente seja qual for a situação do servidor, e se o PHP não alcança o daemon o
 * socket parece saudável e nunca entrega nada. Só um evento entregue prova o
 * caminho inteiro; o poller usa isto para decidir o ritmo (ver startPolling() em
 * index.tsx).
 */
export declare function realtimeDelivered(): boolean;
/**
 * Relê uma mensagem para as flags de permissão do ator virem do servidor. O Show
 * tem o mesmo `defaultInclude` da listagem e traz `deletedBy`, que permite à
 * lápide nomear o moderador.
 *
 * Exportado porque o moderador que exclui também precisa: `whenMessageChanged`
 * exclui o ator, então o próprio cliente não recebe o evento. Ver ChatMessage.delete().
 */
export declare function refreshMessageCapabilities(id: number): void;
