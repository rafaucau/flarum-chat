/**
 * Quem fica por cima quando a gaveta do chat e o composer do fórum se sobrepõem.
 * Por padrão a gaveta fica acima (ver `.ChatDrawer`); ao interagir com o composer
 * ele passa à frente. Usa pointerdown além de focus porque arrastar o resize ou
 * clicar num vão da toolbar não move o foco.
 */
const COMPOSER = '#composer, #avocado-reply-dock, .AvocadoComposerDock, .AvocadoReplyDock';

const FRONT_CLASS = 'ChatComposerFront';

let installed = false;

export function installComposerStacking(): void {
  if (installed) return;

  installed = true;

  const update = (target: EventTarget | null) => {
    const inComposer = target instanceof Element && target.closest(COMPOSER) !== null;

    document.documentElement.classList.toggle(FRONT_CLASS, inComposer);
  };

  document.addEventListener('pointerdown', (e) => update(e.target), true);
  document.addEventListener('focusin', (e) => update(e.target), true);
}
