import app from 'flarum/forum/app';

/**
 * Largura abaixo da qual não cabem dois painéis lado a lado. 767 é
 * `@chat-mobile-breakpoint`, o limite `@phone` do core; centralizado aqui para o
 * JS e o LESS não divergirem.
 */
export const CHAT_MOBILE_BREAKPOINT = 767;

/** Viewport estreito o bastante para o layout de um painel só. */
export function isNarrowViewport(): boolean {
  return window.innerWidth <= CHAT_MOBILE_BREAKPOINT;
}

/**
 * Onde o chat abre: na gaveta ou na página cheia. Resposta única para o botão do
 * header, a notificação de convite, o "iniciar chat" do perfil e a própria gaveta.
 */
export function shouldUseChatDrawer(): boolean {
  // Mesmo teste do `HeaderDropdown#onclick` do core: com o drawer do Flarum aberto
  // estamos no celular com o header em lista, e o toque navega em vez de abrir
  // painel. Separado do teste de largura porque pergunta "está na navegação?".
  if (app.drawer.isOpen()) return false;

  if (app.session.user?.preferences()?.['ramon-chat.openInDrawer'] === false) {
    return false;
  }

  // Abaixo disso a gaveta ocuparia a tela toda com chrome de gaveta. Uma gaveta já
  // aberta que cai aqui (rotação, sessão restaurada) vira o botão flutuante; ver
  // ChatDrawer.
  return !isNarrowViewport();
}
