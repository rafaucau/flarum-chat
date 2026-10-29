import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
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
    onupdate(vnode: Mithril.VnodeDOM<ChatAutocompleteAttrs>): void;
    view(): Mithril.Children;
}
