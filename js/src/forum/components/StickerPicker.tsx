import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

import { playOnHover } from '../utils/stickers';

export interface StickerPickerAttrs extends ComponentAttrs {
  onInsert: (shortcode: string) => void;
  onClose: () => void;
}

/**
 * Seletor de figurinhas do chat.
 *
 * O do ramon/stickers só entra no registro de exports depois de aberto num
 * composer de discussão, então este lê o recurso JSON:API `stickers`. Insere o
 * shortcode `:name:`, que o formatter converte no envio.
 */
export default class StickerPicker extends Component<StickerPickerAttrs> {
  private stickers: any[] = [];
  private loading = true;
  private filter = '';
  private outsideListener?: (e: MouseEvent) => void;

  oninit(vnode: Mithril.Vnode<StickerPickerAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  oncreate(vnode: Mithril.VnodeDOM<StickerPickerAttrs>): void {
    super.oncreate(vnode);

    // Um frame depois: o clique que abriu ainda está propagando e fecharia o seletor.
    requestAnimationFrame(() => {
      this.outsideListener = (e: MouseEvent) => {
        if (!vnode.dom.contains(e.target as Node)) this.attrs.onClose();
      };

      document.addEventListener('click', this.outsideListener);
    });

    (vnode.dom.querySelector('.ChatStickerPicker-search') as HTMLInputElement | null)?.focus();

    playOnHover(vnode.dom as HTMLElement);
  }

  /** Hidrata a cada render: o filtro recria a grade com nós novos. */
  onupdate(vnode: Mithril.VnodeDOM<StickerPickerAttrs>): void {
    super.onupdate(vnode);

    playOnHover(vnode.dom as HTMLElement);
  }

  onremove(): void {
    if (this.outsideListener) document.removeEventListener('click', this.outsideListener);
  }

  view(): Mithril.Children {
    const term = this.filter.trim().toLowerCase();

    const shown = term
      ? this.stickers.filter((sticker) =>
          `${sticker.title() ?? ''} ${sticker.textToReplace() ?? ''} ${sticker.categoryName() ?? ''}`.toLowerCase().includes(term)
        )
      : this.stickers;

    return (
      <div className="ChatStickerPicker" onkeydown={(e: KeyboardEvent) => this.onKey(e)}>
        <input
          className="FormControl ChatStickerPicker-search"
          type="search"
          placeholder={app.translator.trans('ramon-chat.forum.composer.sticker_search', {}, true)}
          value={this.filter}
          oninput={(e: Event) => {
            this.filter = (e.target as HTMLInputElement).value;
          }}
        />

        {this.loading ? (
          <div className="ChatStickerPicker-loading">
            <LoadingIndicator display="inline" size="small" />
          </div>
        ) : shown.length === 0 ? (
          <div className="ChatStickerPicker-empty">{app.translator.trans('ramon-chat.forum.composer.sticker_none')}</div>
        ) : (
          <div className="ChatStickerPicker-grid">
            {shown.map((sticker) => (
              <button
                type="button"
                key={sticker.id()}
                className="ChatStickerPicker-item"
                title={sticker.title() ?? sticker.textToReplace()}
                onclick={() => this.attrs.onInsert(sticker.textToReplace() ?? '')}
              >
                {this.thumbnail(sticker)}
              </button>
            ))}
          </div>
        )}
      </div>
    );
  }

  /**
   * Miniatura no mesmo markup que o ramon/stickers gera. `.tgs` e `.json` são
   * Lottie e não rodam em `<img>`; o MutationObserver da extensão em
   * `document.body` os anima. Caminho relativo é servido a partir da raiz do fórum.
   */
  protected thumbnail(sticker: any): Mithril.Children {
    const path = this.absolute(String(sticker.path() ?? ''));
    const title = sticker.title() ?? '';
    const lower = path.toLowerCase();

    if (lower.endsWith('.tgs')) {
      return <span className="Sticker Sticker--tgs" data-tgs={path} title={title} />;
    }

    if (lower.endsWith('.json')) {
      return <span className="Sticker Sticker--lottie" data-lottie={path} title={title} />;
    }

    return (
      <span className="Sticker">
        <img className="sticker" src={path} alt={title} loading="lazy" />
      </span>
    );
  }

  protected absolute(path: string): string {
    if (/^https?:\/\//i.test(path)) return path;

    const base = String(app.forum.attribute('baseUrl') ?? '').replace(/\/$/, '');

    return base + (path.startsWith('/') ? path : `/${path}`);
  }

  protected onKey(e: KeyboardEvent): void {
    if (e.key === 'Escape') {
      e.stopPropagation();
      this.attrs.onClose();
    }
  }

  protected async load(): Promise<void> {
    try {
      const results = await app.store.find<any[]>('stickers', {
        page: { limit: 200 },
      });

      this.stickers = Array.isArray(results) ? results : [];
    } catch {
      this.stickers = [];
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}
