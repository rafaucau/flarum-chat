import app from 'flarum/forum/app';

import chatState from '../state/chat';
import ChatDrawer from '../components/ChatDrawer';

/**
 * Atalhos globais do chat. Poucos e sempre com modificador: numa página cheia de
 * campos de texto, a única tecla solta tratada é Escape, e só quando o chat tem um
 * modo aberto.
 *
 *   Alt+C          abre/fecha a gaveta
 *   Alt+Shift+C    vai para o chat em tela cheia
 *   Alt+K          busca, no escopo do canal aberto
 *   Escape         sai da seleção, depois fecha o painel de tópico/fixadas
 *
 * Alt em vez de Ctrl/Cmd: o navegador e o SO já usam quase toda combinação com Ctrl.
 */
export function bindShortcuts(): void {
  document.addEventListener('keydown', onKeyDown);
}

function onKeyDown(e: KeyboardEvent): void {
  // Nunca rouba tecla de um campo. `isContentEditable` cobre o composer rico, que é div.
  const target = e.target as HTMLElement | null;
  const typing = !!target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);

  if (e.key === 'Escape') {
    // Escape durante a digitação só para modos que o composer não trata; o
    // ChatComposer interrompe a propagação no uso dele.
    if (handleEscape()) {
      e.preventDefault();
    }

    return;
  }

  if (typing || !e.altKey || e.ctrlKey || e.metaKey) return;

  const key = e.key.toLowerCase();

  if (key === 'c') {
    e.preventDefault();

    if (e.shiftKey) {
      m.route.set(chatState.activeChannelId ? app.route('chat.channel', { id: chatState.activeChannelId }) : app.route('chat.index'));

      return;
    }

    if (chatState.drawerOpen) {
      chatState.setDrawerOpen(false);
    } else {
      ChatDrawer.open();
    }

    m.redraw();

    return;
  }

  if (key === 'k') {
    e.preventDefault();

    m.route.set(chatState.activeChannelId ? app.route('chat.search', { channel: chatState.activeChannelId }) : app.route('chat.search'));
  }
}

/**
 * Desfaz uma camada de estado do chat. Retorna se fechou algo, para o Escape seguir
 * para o resto da página quando o chat não tem modo aberto.
 */
function handleEscape(): boolean {
  if (chatState.selecting) {
    chatState.selecting = false;
    chatState.selected.clear();
    m.redraw();

    return true;
  }

  if (chatState.activeThreadId !== null) {
    chatState.closeThread();
    m.redraw();

    return true;
  }

  if (chatState.showPinned) {
    chatState.showPinned = false;
    m.redraw();

    return true;
  }

  return false;
}

/** Tecla de envio: Enter, a menos que o membro tenha escolhido Ctrl+Enter. */
export type SendKey = 'enter' | 'ctrl';

/**
 * Preferência do membro, Enter por padrão. Qualquer valor diferente de "ctrl" vale
 * Enter, o que cobre o antigo "default" gravado sem reescrever linhas.
 */
export function sendKeyPreference(): SendKey {
  return app.session.user?.preferences()?.['ramon-chat.sendWithCtrlEnter'] === 'ctrl' ? 'ctrl' : 'enter';
}

/** Lido a cada chamada: um cache manteria o comportamento antigo após a troca. */
export function sendsOnCtrlEnter(): boolean {
  return sendKeyPreference() === 'ctrl';
}
