/**
 * Morph (FLIP) entre superfícies do chat, no estilo do composer flutuante do
 * Avocado: a nova superfície nasce do retângulo de quem a abriu.
 *
 * `rememberOrigin` grava a origem e `morphIn` a consome. A origem vale por pouco
 * tempo e só para o alvo nomeado, para uma navegação qualquer não herdar animação.
 */

export type MorphTarget = 'page' | 'drawer';

interface Origin {
  target: MorphTarget;
  rect: DOMRect;
  radius: string;
  bg: string;
  shadow: string;
  at: number;
}

const MORPH_MS = 520;
const CONTENT_DELAY_MS = 260;
const ORIGIN_TTL_MS = 1500;

const EASE_LAND = 'cubic-bezier(0.2, 1.12, 0.32, 1)';
const EASE_OUT = 'cubic-bezier(0.4, 0, 0.2, 1)';

let origin: Origin | null = null;

export const reducedMotion = (): boolean => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

/** Grava o elemento que abre `target` como origem; elemento sem caixa não grava nada. */
export function rememberOrigin(el: Element | null | undefined, target: MorphTarget): void {
  if (!(el instanceof HTMLElement)) return;

  const rect = el.getBoundingClientRect();

  if (rect.width === 0 || rect.height === 0) return;

  const cs = getComputedStyle(el);

  origin = {
    target,
    rect,
    radius: cs.borderTopLeftRadius,
    bg: cs.backgroundColor,
    shadow: cs.boxShadow,
    at: Date.now(),
  };
}

/**
 * Leva `el` do retângulo gravado até o lugar dele. Retorna se animou; sem origem
 * recente para o alvo, ou com reduced motion, não faz nada.
 */
export function morphIn(el: Element | null | undefined, target: MorphTarget, contentDelay = CONTENT_DELAY_MS): boolean {
  const from = origin;

  if (!from || from.target !== target) return false;

  origin = null;

  if (!(el instanceof HTMLElement) || Date.now() - from.at > ORIGIN_TTL_MS) return false;
  if (reducedMotion() || typeof el.animate !== 'function') return false;

  const home = el.getBoundingClientRect();

  if (home.width === 0 || home.height === 0) return false;

  const sx = from.rect.width / home.width;
  const sy = from.rect.height / home.height;
  const radius = parseFloat(from.radius) || 0;
  const own = getComputedStyle(el);

  el.animate(
    [
      {
        transformOrigin: 'top left',
        transform: `translate(${from.rect.left - home.left}px, ${from.rect.top - home.top}px) scale(${sx}, ${sy})`,
        borderRadius: `${radius / sx}px / ${radius / sy}px`,
        backgroundColor: from.bg,
        boxShadow: from.shadow,
      },
      {
        transformOrigin: 'top left',
        transform: 'none',
        borderRadius: own.borderTopLeftRadius,
        backgroundColor: own.backgroundColor,
        boxShadow: own.boxShadow,
      },
    ],
    { duration: MORPH_MS, easing: EASE_LAND }
  );

  cascadeIn(el, contentDelay);

  return true;
}

/** Conteúdo entra em cascata quando a superfície está quase pousando. */
export function cascadeIn(el: HTMLElement, delay = CONTENT_DELAY_MS): void {
  if (reducedMotion() || typeof el.animate !== 'function') return;

  Array.from(el.children).forEach((child, i) =>
    child.animate(
      [
        { opacity: 0, transform: 'translateY(6px)' },
        { opacity: 1, transform: 'none' },
      ],
      {
        duration: 240,
        delay: delay + i * 40,
        easing: EASE_OUT,
        fill: 'backwards',
      }
    )
  );
}
