import app from 'flarum/forum/app';

/**
 * Roda o callback quando nenhum modal estiver na tela, e destrava o latch de
 * fechamento do core se o modal nunca terminar de fechar.
 *
 * `ModalManager.animateHide()` liga `modalClosing` e só o limpa (e chama
 * `state.close()`) num `transitionend`. Se o evento não vem (troca de rota no
 * mesmo tick, reduced motion, aba em segundo plano), nenhum modal fecha mais até
 * recarregar. O listener do core é `{ once: true }` e não lê o evento, então
 * disparar um `transitionend` sintético faz exatamente a limpeza pendente.
 *
 * @param callback O que rodar quando a tela estiver livre.
 * @param maxFrames Limite da espera; a ~60fps, bem acima da transição de 200ms do core.
 */
export default function afterModalClosed(callback: () => void, maxFrames = 40): void {
  let frames = 0;

  const tick = () => {
    if (!modalStillOpen()) {
      callback();

      return;
    }

    if (frames++ >= maxFrames) {
      unstickModalManager();
      callback();

      return;
    }

    requestAnimationFrame(tick);
  };

  // Pelo menos um frame: `hide()` acabou de ser chamado e a lista ainda não mudou.
  requestAnimationFrame(tick);
}

function modalStillOpen(): boolean {
  return ((app.modal as any)?.modalList?.length ?? 0) > 0;
}

/**
 * Dispara à mão o `transitionend` que o core espera, no diálogo com a classe
 * `out` (a que `animateHide()` adiciona). Não borbulha: o backdrop também escuta
 * `transitionend` e não deve reagir a um evento sintético.
 */
function unstickModalManager(): void {
  const dialog = document.querySelector<HTMLElement>('.ModalManager .Modal.out');

  if (!dialog) return;

  dialog.dispatchEvent(new Event('transitionend', { bubbles: false }));
}
