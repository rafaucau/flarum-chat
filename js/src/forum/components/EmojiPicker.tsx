import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import { searchEmoji, resolveEmoji, loadEmojiMap, emojiMapReady, type EmojiSuggestion } from '../utils/emoji';
import iconLabel from '../utils/iconLabel';

export interface EmojiPickerAttrs extends ComponentAttrs {
  /** Valor atual: shortcode ou emoji Unicode. */
  value: string | null;
  onchange: (value: string | null) => void;
  disabled?: boolean;
}

/**
 * Seletor de emoji com busca, para campos cujo valor é um único emoji.
 *
 * Grava Unicode, não shortcode: `❤️` renderiza em qualquer lugar e sobrevive à
 * extensão desativada; `heart` não significa nada sem esta tabela.
 */
export default class EmojiPicker extends Component<EmojiPickerAttrs> {
  private open = false;
  private query = '';
  private highlighted = 0;

  oninit(vnode: Mithril.Vnode<EmojiPickerAttrs>): void {
    super.oninit(vnode);

    // Pré-carrega o chunk para a grade estar pronta ao abrir.
    loadEmojiMap();
  }

  view(): Mithril.Children {
    const { value, disabled } = this.attrs;
    const selected = resolveEmoji(value);
    const results = this.open ? searchEmoji(this.query) : [];

    return (
      <div className={classList('EmojiPicker', { 'EmojiPicker--open': this.open })}>
        <div className="EmojiPicker-control">
          <span className="EmojiPicker-preview" aria-hidden="true">
            {selected ?? <i className="far fa-face-smile" />}
          </span>

          <input
            className="FormControl EmojiPicker-input"
            type="text"
            value={this.open ? this.query : (selected ?? '')}
            placeholder={app.translator.trans('ramon-chat.forum.emoji_picker.placeholder', {}, true)}
            disabled={disabled}
            onfocus={() => this.openPicker()}
            oninput={(e: Event) => this.onInput(e)}
            onkeydown={(e: KeyboardEvent) => this.onKeyDown(e)}
            aria-expanded={this.open ? 'true' : 'false'}
            aria-autocomplete="list"
          />

          {selected ? (
            <Button
              className="Button Button--icon Button--link EmojiPicker-clear"
              icon="fas fa-times"
              {...iconLabel(app.translator.trans('ramon-chat.forum.emoji_picker.clear', {}, true))}
              disabled={disabled}
              onclick={() => this.choose(null)}
            />
          ) : null}
        </div>

        {this.open ? (
          <div className="EmojiPicker-dropdown">
            {!emojiMapReady() && results.length === 0 ? (
              <LoadingIndicator display="inline" size="small" />
            ) : results.length === 0 ? (
              <div className="EmojiPicker-empty">{app.translator.trans('ramon-chat.forum.emoji_picker.empty')}</div>
            ) : (
              <div className="EmojiPicker-grid">
                {results.map((item, index) => (
                  <button
                    key={item.name}
                    type="button"
                    className={classList('EmojiPicker-option', {
                      'EmojiPicker-option--active': index === this.highlighted,
                    })}
                    title={`:${item.name}:`}
                    // mousedown, não click: o blur do input fecharia o dropdown antes.
                    onmousedown={(e: Event) => {
                      e.preventDefault();
                      this.choose(item.unicode);
                    }}
                  >
                    {item.unicode}
                  </button>
                ))}
              </div>
            )}
          </div>
        ) : null}
      </div>
    );
  }

  oncreate(vnode: Mithril.VnodeDOM<EmojiPickerAttrs>): void {
    super.oncreate(vnode);

    // No document porque qualquer outro ponto do modal deve fechar o dropdown.
    this.outsideHandler = (e: MouseEvent) => {
      if (!this.open) return;
      if (this.element.contains(e.target as Node)) return;

      this.open = false;
      m.redraw();
    };

    document.addEventListener('mousedown', this.outsideHandler);
  }

  onremove(vnode: Mithril.VnodeDOM<EmojiPickerAttrs>): void {
    super.onremove(vnode);

    if (this.outsideHandler) {
      document.removeEventListener('mousedown', this.outsideHandler);
    }
  }

  private outsideHandler?: (e: MouseEvent) => void;

  protected openPicker(): void {
    this.open = true;
    this.query = '';
    this.highlighted = 0;
    m.redraw();
  }

  protected onInput(e: Event): void {
    this.query = (e.target as HTMLInputElement).value;
    this.open = true;
    this.highlighted = 0;
    m.redraw();
  }

  protected onKeyDown(e: KeyboardEvent): void {
    const results = searchEmoji(this.query);

    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        this.highlighted = Math.min(this.highlighted + 1, results.length - 1);
        break;

      case 'ArrowUp':
        e.preventDefault();
        this.highlighted = Math.max(this.highlighted - 1, 0);
        break;

      case 'Enter': {
        e.preventDefault();

        // Enter sem destaque ainda resolve um shortcode exato (ex.: `:tada:` colado).
        const pick = results[this.highlighted] ?? null;
        this.choose(pick ? pick.unicode : resolveEmoji(this.query));
        break;
      }

      case 'Escape':
        e.preventDefault();
        this.open = false;
        break;

      default:
        return;
    }

    m.redraw();
  }

  protected choose(value: string | null): void {
    this.open = false;
    this.query = '';
    this.attrs.onchange(value);
    m.redraw();
  }
}
