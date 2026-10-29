import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import classList from 'flarum/common/utils/classList';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';

/**
 * Uma sugestão. `insert` substitui o trecho digitado e `label` é o que a linha
 * mostra; num emoji diferem (glifo vs. shortcode). `emoji` é `Children` porque
 * emoji customizado do Flamoji é imagem.
 */
export interface Suggestion {
  key: string;
  insert: string;
  label: string;
  hint?: string | null;
  user?: User | null;
  emoji?: Mithril.Children;
  icon?: string | null;
}

export interface ChatAutocompleteAttrs extends ComponentAttrs {
  suggestions: Suggestion[];
  activeIndex: number;
  onSelect: (suggestion: Suggestion) => void;
  onHover: (index: number) => void;
}

/**
 * Lista de sugestões acima do composer. Só desenha e reporta cliques; gatilho,
 * busca e inserção ficam no ChatComposer, que conhece o cursor.
 */
export default class ChatAutocomplete extends Component<ChatAutocompleteAttrs> {
  onupdate(vnode: Mithril.VnodeDOM<ChatAutocompleteAttrs>): void {
    // `view()` devolve null quando não há sugestão, e aí não existe `vnode.dom`.
    if (!vnode.dom) return;

    const active = vnode.dom.querySelector('.ChatAutocomplete-item--active');

    (active as HTMLElement | null)?.scrollIntoView({ block: 'nearest' });
  }

  view(): Mithril.Children {
    const { suggestions, activeIndex, onSelect, onHover } = this.attrs;

    if (suggestions.length === 0) return null;

    return (
      <div className="ChatAutocomplete" role="listbox">
        {suggestions.map((suggestion, index) => (
          <button
            key={suggestion.key}
            type="button"
            role="option"
            aria-selected={index === activeIndex}
            className={classList('ChatAutocomplete-item', {
              'ChatAutocomplete-item--active': index === activeIndex,
            })}
            // mousedown, não click: o blur do textarea fecharia a lista antes.
            onmousedown={(e: MouseEvent) => {
              e.preventDefault();
              onSelect(suggestion);
            }}
            onmouseenter={() => onHover(index)}
          >
            {suggestion.user ? <Avatar user={suggestion.user} className="Avatar" /> : null}
            {suggestion.emoji ? <span>{suggestion.emoji}</span> : null}
            {suggestion.icon ? <i className={suggestion.icon} aria-hidden="true" /> : null}

            <span>{suggestion.label}</span>

            {suggestion.hint ? <span className="ChatAutocomplete-item-hint">{suggestion.hint}</span> : null}
          </button>
        ))}
      </div>
    );
  }
}
