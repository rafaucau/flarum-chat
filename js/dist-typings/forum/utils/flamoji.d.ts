import type Mithril from 'mithril';
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
 * Se o Flamoji está instalado e ativo, testado pelo componente do botão, que é o
 * que o composer usa. Ao contrário do picker do ramon/stickers, esse componente é
 * registrado no bundle principal no boot.
 */
export declare function flamojiAvailable(): boolean;
/**
 * Busca os emojis custom uma vez por página, direto no endpoint que o picker do
 * Flamoji usa. Fora do store: são só leitura aqui, e o store dependeria do model
 * `flamojis` registrado. Redesenha ao terminar para chips e autocomplete atualizarem.
 */
export declare function loadCustomEmoji(): void;
/** O emoji custom de um shortcode, ou null. */
export declare function customEmoji(shortcode: string | null | undefined): CustomEmoji | null;
/**
 * Busca emojis custom para o autocomplete de `:`, com o mesmo ranking de
 * `searchEmoji` (prefixo antes de substring) para a lista mesclada ficar coerente.
 */
export declare function searchCustomEmoji(query: string, limit?: number): CustomEmoji[];
/** O `<img>` de um emoji custom, dimensionado pelo nosso LESS. */
export declare function customEmojiImage(emoji: CustomEmoji, className?: string): Mithril.Children;
/**
 * Botão de emoji do composer, ou nada sem o Flamoji.
 *
 * @param onInsert Recebe o emoji: shortcode para custom, o glifo para padrão,
 *   conforme o `picker_set` do admin.
 * @param disabled Acompanha as outras ferramentas durante um envio.
 */
export declare function flamojiPickerButton(onInsert: (text: string) => void, disabled?: boolean): Mithril.Children;
