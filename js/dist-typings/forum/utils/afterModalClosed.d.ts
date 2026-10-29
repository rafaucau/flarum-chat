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
export default function afterModalClosed(callback: () => void, maxFrames?: number): void;
