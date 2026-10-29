import type ChatState from '../state/ChatState';
/**
 * Centraliza uma linha do stream e a destaca por alguns segundos. Busca dentro do
 * scroller que contém `from`: o painel de tópico e o canal desenham o mesmo
 * `ChatMessage`, e uma busca no documento rolaria o stream errado.
 *
 * Retorna `false` quando a linha não está na janela carregada, para quem chama avisar.
 */
export declare function jumpToMessage(id: number | string, from?: HTMLElement | null): boolean;
/**
 * Salta para uma mensagem do canal, carregando antes o trecho de histórico que a
 * separa da janela quando ela é mais antiga. O scroller é relido após carregar
 * porque o redesenho pode tê-lo recriado. Retorna `false` só quando a mensagem não
 * pode estar no stream: resposta de tópico, apagada ou além do limite de páginas.
 */
export declare function revealAndJump(state: ChatState, channelId: number, id: number | string, from?: HTMLElement | null): Promise<boolean>;
