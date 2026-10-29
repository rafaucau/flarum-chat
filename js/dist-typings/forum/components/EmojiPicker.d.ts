import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
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
    private open;
    private query;
    private highlighted;
    oninit(vnode: Mithril.Vnode<EmojiPickerAttrs>): void;
    view(): Mithril.Children;
    oncreate(vnode: Mithril.VnodeDOM<EmojiPickerAttrs>): void;
    onremove(vnode: Mithril.VnodeDOM<EmojiPickerAttrs>): void;
    private outsideHandler?;
    protected openPicker(): void;
    protected onInput(e: Event): void;
    protected onKeyDown(e: KeyboardEvent): void;
    protected choose(value: string | null): void;
}
