/**
 * Morph (FLIP) entre superfícies do chat, no estilo do composer flutuante do
 * Avocado: a nova superfície nasce do retângulo de quem a abriu.
 *
 * `rememberOrigin` grava a origem e `morphIn` a consome. A origem vale por pouco
 * tempo e só para o alvo nomeado, para uma navegação qualquer não herdar animação.
 */
export type MorphTarget = 'page' | 'drawer';
export declare const reducedMotion: () => boolean;
/** Grava o elemento que abre `target` como origem; elemento sem caixa não grava nada. */
export declare function rememberOrigin(el: Element | null | undefined, target: MorphTarget): void;
/**
 * Leva `el` do retângulo gravado até o lugar dele. Retorna se animou; sem origem
 * recente para o alvo, ou com reduced motion, não faz nada.
 */
export declare function morphIn(el: Element | null | undefined, target: MorphTarget, contentDelay?: number): boolean;
/** Conteúdo entra em cascata quando a superfície está quase pousando. */
export declare function cascadeIn(el: HTMLElement, delay?: number): void;
