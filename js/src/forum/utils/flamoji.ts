import app from 'flarum/forum/app';
import type Mithril from 'mithril';

/**
 * Integração opcional com pianotell/flarum-ext-flamoji, toda resolvida pelo
 * registro de exports em runtime: um `import` estático de `ext:pianotell-flamoji/…`
 * tornaria a extensão obrigatória.
 *
 * O corpo das mensagens já funciona sozinho: passa pelo `Formatter` do core, onde o
 * Flamoji registra suas substituições. Este módulo cobre o que fica fora do
 * formatter: o botão no composer (que não é o `TextEditor` estendido pelo Flamoji),
 * o autocomplete de `:` e os chips de reação.
 */

/** Namespace dos módulos do Flamoji (o id da extensão). */
const EXTENSION = 'pianotell-flamoji';

/** De onde o picker do Flamoji lê o conjunto completo. Sem autenticação. */
const ALL_ENDPOINT = '/flamojis/all';

export interface CustomEmoji {
  /** Shortcode sem dois-pontos (`kappa`); a chave de toda comparação. */
  name: string;
  /** O gatilho exato como gravado, geralmente `:kappa:`. */
  insert: string;
  /** URL absoluta da imagem. */
  url: string;
  /** Rótulo definido pelo admin, ou o nome. */
  title: string;
}

/**
 * Lê um módulo do registro de exports. `checkModule` em vez de `get` porque devolve
 * `false` sem lançar, e Flamoji ausente não é erro.
 */
function registryModule(path: string): any {
  const registry = (window as any)?.flarum?.reg;

  if (!registry) return null;

  try {
    return typeof registry.checkModule === 'function' ? registry.checkModule(EXTENSION, path) : registry.get?.(EXTENSION, path);
  } catch {
    return null;
  }
}

/**
 * Se o Flamoji está instalado e ativo, testado pelo componente do botão, que é o
 * que o composer usa. Ao contrário do picker do ramon/stickers, esse componente é
 * registrado no bundle principal no boot.
 */
export function flamojiAvailable(): boolean {
  return Boolean(registryModule('forum/components/FlamojiPickerButton'));
}

/**
 * URL absoluta de um caminho gravado. Espelha `ConfigureTextFormatter::__invoke` no
 * servidor (`^https?://`, senão relativo ao fórum); se divergirem, o picker oferece
 * uma imagem que a mensagem não mostra.
 */
function absolutise(path: string): string {
  if (/^https?:\/\//i.test(path)) return path;

  const base = String(app.forum.attribute('baseUrl') ?? '').replace(/\/+$/, '');

  return base + (path.startsWith('/') ? path : '/' + path);
}

/** Remove os delimitadores de `:name:`, igual ao `normalise` de `emoji.ts`. */
function bareName(trigger: string): string {
  return trigger.trim().replace(/^:+|:+$/g, '');
}

let all: CustomEmoji[] = [];
let byName: Map<string, CustomEmoji> = new Map();
let loaded = false;
let loading = false;

/**
 * Extrai os emojis da resposta de `/flamojis/all`, que não é JSON:API: os models
 * vêm como propriedades com chave numérica ao lado de `jsonapi`, com as colunas no
 * topo:
 *
 *     { "0": { "id": 3, "text_to_replace": ":kappa:", "path": "…" },
 *       "jsonapi": { "version": "1.1" } }
 *
 * O ramo `data` é seguro para o caso de o endpoint virar um documento de verdade.
 */
function extractRecords(response: any): Array<Record<string, unknown>> {
  if (!response || typeof response !== 'object') return [];

  if (Array.isArray(response.data)) {
    return response.data.map((entry: any) => ({
      ...(entry?.attributes ?? {}),
      ...entry,
    }));
  }

  return Object.keys(response)
    .filter((key) => key !== '' && !isNaN(Number(key)))
    .map((key) => response[key])
    .filter((entry) => entry && typeof entry === 'object');
}

/**
 * Busca os emojis custom uma vez por página, direto no endpoint que o picker do
 * Flamoji usa. Fora do store: são só leitura aqui, e o store dependeria do model
 * `flamojis` registrado. Redesenha ao terminar para chips e autocomplete atualizarem.
 */
export function loadCustomEmoji(): void {
  if (loaded || loading || !flamojiAvailable()) return;

  loading = true;

  app
    .request<any>({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + ALL_ENDPOINT,
    })
    .then((response) => {
      const records = extractRecords(response);
      const next: CustomEmoji[] = [];
      const index = new Map<string, CustomEmoji>();

      for (const record of records) {
        const trigger = String(record.text_to_replace ?? '').trim();
        const path = String(record.path ?? '').trim();

        // O servidor ignora linhas sem uma das metades ao configurar o formatter; o
        // picker inseriria um gatilho que não renderiza nada.
        if (trigger === '' || path === '') continue;

        const name = bareName(trigger);

        if (name === '') continue;

        const emoji: CustomEmoji = {
          name,
          insert: trigger,
          url: absolutise(path),
          title: String(record.title ?? '').trim() || name,
        };

        next.push(emoji);

        // Indexado em minúsculas porque o endpoint de reação compara sem diferenciar
        // caixa. O primeiro vence, para um gatilho duplicado não deslocar o que o
        // formatter usaria.
        const key = name.toLowerCase();

        if (!index.has(key)) index.set(key, emoji);
      }

      all = next;
      byName = index;
      loaded = true;

      m.redraw();
    })
    .catch(() => {
      // Endpoint fora do ar deixa o chat como sem Flamoji: emojis Unicode e os custom
      // como shortcode.
      loaded = true;
    })
    .finally(() => {
      loading = false;
    });
}

/** O emoji custom de um shortcode, ou null. */
export function customEmoji(shortcode: string | null | undefined): CustomEmoji | null {
  if (!shortcode) return null;

  loadCustomEmoji();

  return byName.get(bareName(shortcode).toLowerCase()) ?? null;
}

/**
 * Busca emojis custom para o autocomplete de `:`, com o mesmo ranking de
 * `searchEmoji` (prefixo antes de substring) para a lista mesclada ficar coerente.
 */
export function searchCustomEmoji(query: string, limit = 8): CustomEmoji[] {
  loadCustomEmoji();

  const term = bareName(query).toLowerCase();

  if (term === '') return all.slice(0, limit);

  const prefix: CustomEmoji[] = [];
  const contains: CustomEmoji[] = [];

  for (const emoji of all) {
    const at = emoji.name.toLowerCase().indexOf(term);

    if (at === 0) prefix.push(emoji);
    else if (at > 0) contains.push(emoji);

    if (prefix.length >= limit) break;
  }

  return [...prefix, ...contains].slice(0, limit);
}

/** O `<img>` de um emoji custom, dimensionado pelo nosso LESS. */
export function customEmojiImage(emoji: CustomEmoji, className = 'ChatFlamoji'): Mithril.Children {
  return m('img', {
    className,
    src: emoji.url,
    alt: emoji.title,
    title: `:${emoji.name}:`,
    loading: 'lazy',
  });
}

/**
 * Contêineres onde um composer do chat pode estar. O picker precisa ficar dentro
 * dele, não só do viewport: a gaveta é um painel estreito sobre o fórum.
 */
const PANEL_SELECTOR = '.ChatDrawer, .ChatPage, .ChatThreadPanel';

/**
 * Posiciona o picker num composer do chat. O Flamoji centraliza o popup no botão, o
 * que na gaveta faz um popup de 400px sair do painel. Aqui fica acima do botão e
 * alinhado à direita, como o picker de stickers.
 *
 * Mantém os contratos do Flamoji: não faz nada até o elemento ter medida (o
 * emoji-mart preenche o Shadow DOM de forma assíncrona) e limita ao viewport por último.
 */
function positionInChat(el: HTMLElement, button: HTMLElement): void {
  const btn = button.getBoundingClientRect();
  const box = el.getBoundingClientRect();

  if (!box.width || !box.height) return;

  const margin = 6;
  const pad = 8;

  const panel = button.closest(PANEL_SELECTOR) as HTMLElement | null;
  const bounds = panel?.getBoundingClientRect() ?? null;

  let left = btn.right - box.width;
  let top = btn.top - margin - box.height;

  if (bounds) {
    // Popup mais largo que o painel é centralizado nele, em vez de preso a uma borda.
    left =
      box.width <= bounds.width - pad * 2
        ? Math.min(Math.max(left, bounds.left + pad), bounds.right - box.width - pad)
        : bounds.left + (bounds.width - box.width) / 2;
  }

  // O viewport vence o painel: um popup fora da tela seria inutilizável.
  left = Math.min(Math.max(left, pad), window.innerWidth - box.width - pad);
  top = Math.min(Math.max(top, pad), window.innerHeight - box.height - pad);

  el.style.left = `${Math.round(left)}px`;
  el.style.top = `${Math.round(top)}px`;
}

/** Em cache para a classe ser criada uma vez só. */
let buttonComponent: any = null;

/**
 * Botão do próprio Flamoji, hospedado no composer do chat. `FlamojiPickerButton`
 * cuida de todo o ciclo do picker e fala com o host por `getButtonElement()` e
 * `insertText()`; a subclasse só troca o segundo.
 *
 * Criada sob demanda: o registro é preenchido no initializer do Flamoji, e lê-lo
 * na avaliação deste módulo pegaria `undefined` em algumas ordens de carga.
 */
function pickerButton(): any {
  if (buttonComponent) return buttonComponent;

  const Base = registryModule('forum/components/FlamojiPickerButton');

  if (!Base) return null;

  buttonComponent = class ChatFlamojiPickerButton extends Base {
    static initAttrs(attrs: any): void {
      super.initAttrs(attrs);

      // `TextEditorButton.initAttrs` sobrescreve a lista de classes, então anexa depois.
      // `Button-flamoji` precisa ficar: é o que `getButtonElement()` procura.
      attrs.className = `${attrs.className} ChatComposer-tool`;
    }

    oninit(vnode: Mithril.Vnode): void {
      super.oninit(vnode);

      // `position()` é o ponto comum do picker e do placeholder de carregamento, então
      // os dois abrem no mesmo lugar. Trocado na instância porque o controller não
      // aceita opção de posição; protegido para um interno renomeado cair no
      // posicionamento do Flamoji em vez de lançar.
      const controller = (this as any).controller;

      if (typeof controller?.position !== 'function') return;

      controller.position = (el: HTMLElement | null) => {
        const anchor = this.getButtonElement();

        if (el && anchor) positionInChat(el, anchor);
      };
    }

    /** Para onde vai o resultado do picker, em vez do cursor de um `TextEditor`. */
    insertText(text: string): void {
      this.attrs.onInsert?.(text);
    }
  };

  return buttonComponent;
}

/**
 * Botão de emoji do composer, ou nada sem o Flamoji.
 *
 * @param onInsert Recebe o emoji: shortcode para custom, o glifo para padrão,
 *   conforme o `picker_set` do admin.
 * @param disabled Acompanha as outras ferramentas durante um envio.
 */
export function flamojiPickerButton(onInsert: (text: string) => void, disabled = false): Mithril.Children {
  const Button = pickerButton();

  if (!Button) return null;

  return m(Button, {
    // O Flamoji só lê `composer.editor` no próprio `insertText`, que a subclasse troca.
    composer: null,
    onInsert,
    disabled,
    title: app.translator.trans('ramon-chat.forum.composer.emoji', {}, true),
  });
}
