/**
 * Largura abaixo da qual não cabem dois painéis lado a lado. 767 é
 * `@chat-mobile-breakpoint`, o limite `@phone` do core; centralizado aqui para o
 * JS e o LESS não divergirem.
 */
export declare const CHAT_MOBILE_BREAKPOINT = 767;
/** Viewport estreito o bastante para o layout de um painel só. */
export declare function isNarrowViewport(): boolean;
/**
 * Onde o chat abre: na gaveta ou na página cheia. Resposta única para o botão do
 * header, a notificação de convite, o "iniciar chat" do perfil e a própria gaveta.
 */
export declare function shouldUseChatDrawer(): boolean;
