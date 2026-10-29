import app from 'flarum/forum/app';
import type Mithril from 'mithril';

import { loadStickerPicker } from './lazy';

/**
 * Integração opcional com ramon/stickers. Nada é importado da extensão: o chat lê
 * os dados pela API e desenha o próprio painel, pois um `import` estático de
 * `ext:ramon-stickers/…` a tornaria obrigatória.
 *
 * O conteúdo passa pelo formatter do core, onde os stickers registram suas
 * substituições, e o MutationObserver deles hidrata os animados onde aparecerem.
 */

/**
 * Se ramon/stickers está instalado e tem dados. Lido do registro de models do
 * store, não do de exports: o `StickerPicker` deles só se registra depois de aberto
 * num composer de discussão, enquanto o model é registrado no boot.
 */
/** Namespace sob o qual ramon/stickers registra seus módulos. */
const EXTENSION = 'ramon-stickers';

export function stickersAvailable(): boolean {
  // A extensão precisa estar instalada e o ator precisa poder enviar stickers; não
  // desenha um botão que o servidor recusaria.
  if (!app.forum.attribute<boolean>('canSendChatStickers')) return false;

  return Boolean((app.store as any)?.models?.stickers);
}

/**
 * Abre o picker sobre a página e insere o shortcode escolhido. Montado num nó no
 * body porque o composer corta o overflow.
 *
 * @param trigger Botão que o abriu, para posicionar o painel.
 * @param onInsert Recebe o shortcode, ex.: `:wave:`.
 */
export async function openStickerPicker(trigger: HTMLElement | null, onInsert: (text: string) => void): Promise<void> {
  close();

  const StickerPicker = (await loadStickerPicker()).default;

  close();

  const mount = document.createElement('div');
  mount.className = 'ChatStickerPicker-wrapper';
  document.body.appendChild(mount);

  openMount = mount;

  if (trigger) {
    const box = trigger.getBoundingClientRect();

    // Acima do botão e alinhado à direita, onde há espaço num composer preso embaixo.
    mount.style.position = 'fixed';
    mount.style.bottom = `${window.innerHeight - box.top + 8}px`;
    mount.style.right = `${Math.max(8, window.innerWidth - box.right)}px`;
  }

  m.mount(mount, {
    view: (): Mithril.Children =>
      m(StickerPicker, {
        onInsert: (text: string) => {
          onInsert(text);
          close();
        },
        onClose: () => close(),
      }),
  });
}

/** Um por vez: um segundo clique fecha em vez de empilhar. */
let openMount: HTMLElement | null = null;

export function close(): void {
  if (!openMount) return;

  m.mount(openMount, null);
  openMount.remove();
  openMount = null;
}

/**
 * Hidrata os stickers animados de um nó, pausados até o hover. O observer da
 * extensão usaria a opção do admin, e com `hover_play` desligado a grade inteira
 * animaria junta. No stream vale a configuração; no picker é forçado.
 *
 * Os renderers deles pulam nós já inicializados, então rodar antes faz o observer
 * ignorá-los. Esses dois módulos, ao contrário do picker, registram no boot.
 */
export function playOnHover(node: HTMLElement): void {
  const registry = (window as any)?.flarum?.reg;

  if (!registry) return;

  const renderers: Array<[string, string]> = [
    ['common/utils/renderLottie', 'initLottieInRoot'],
    ['common/utils/renderTgs', 'initTgsInRoot'],
  ];

  for (const [path, fn] of renderers) {
    try {
      const module = typeof registry.checkModule === 'function' ? registry.checkModule(EXTENSION, path) : registry.get?.(EXTENSION, path);

      module?.[fn]?.(node, { hoverPlay: true });
    } catch {
      // Renderer ausente ou alterado não deve quebrar o painel; o sticker fica no
      // primeiro frame.
    }
  }
}

export function isStickerPickerOpen(): boolean {
  return openMount !== null;
}

/**
 * Mesmo desenho que os stickers usam no composer de discussão. SVG inline porque
 * nem todo fórum carrega o estilo Font Awesome do ícone equivalente. O path é
 * duplicado: importá-lo tornaria os stickers dependência de build.
 */
const STICKER_ICON_PATH =
  'M20,11.5 L20,7.5 C20,5.56700338 18.4329966,4 16.5,4 L7.5,4 C5.56700338,4 4,5.56700338 4,7.5 ' +
  'L4,16.5 C4,18.4329966 5.56700338,20 7.5,20 L12.5,20 C13.3284271,20 14,19.3284271 14,18.5 ' +
  'L14,16.5 C14,14.5670034 15.5670034,13 17.5,13 L18.5,13 C19.3284271,13 20,12.3284271 20,11.5 Z ' +
  'M19.9266247,13.5532532 C19.522053,13.8348821 19.0303092,14 18.5,14 L17.5,14 ' +
  'C16.1192881,14 15,15.1192881 15,16.5 L15,18.5 C15,18.9222858 14.8952995,19.3201175 14.7104416,19.668952 ' +
  'C17.4490113,18.8255402 19.5186665,16.4560464 19.9266247,13.5532532 Z';

export function stickerIcon(): Mithril.Children {
  return m(
    'svg',
    {
      fill: 'currentColor',
      viewBox: '0 0 24 24',
      xmlns: 'http://www.w3.org/2000/svg',
      // 18px, não 16: o desenho tem muita margem no viewBox de 24 e pareceria menor que
      // o clipe.
      style: { display: 'block', width: '18px', height: '18px' },
      'aria-hidden': 'true',
    },
    m('path', { d: STICKER_ICON_PATH })
  );
}

/** Só faz sentido com a extensão presente. */
export function stickerLabel(): string {
  return app.translator.trans('ramon-chat.forum.composer.sticker', {}, true);
}
