import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
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
    private stickers;
    private loading;
    private filter;
    private outsideListener?;
    oninit(vnode: Mithril.Vnode<StickerPickerAttrs>): void;
    oncreate(vnode: Mithril.VnodeDOM<StickerPickerAttrs>): void;
    /** Hidrata a cada render: o filtro recria a grade com nós novos. */
    onupdate(vnode: Mithril.VnodeDOM<StickerPickerAttrs>): void;
    onremove(): void;
    view(): Mithril.Children;
    /**
     * Miniatura no mesmo markup que o ramon/stickers gera. `.tgs` e `.json` são
     * Lottie e não rodam em `<img>`; o MutationObserver da extensão em
     * `document.body` os anima. Caminho relativo é servido a partir da raiz do fórum.
     */
    protected thumbnail(sticker: any): Mithril.Children;
    protected absolute(path: string): string;
    protected onKey(e: KeyboardEvent): void;
    protected load(): Promise<void>;
}
