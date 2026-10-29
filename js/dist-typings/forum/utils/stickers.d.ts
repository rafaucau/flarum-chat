import type Mithril from 'mithril';
export declare function stickersAvailable(): boolean;
/**
 * Abre o picker sobre a página e insere o shortcode escolhido. Montado num nó no
 * body porque o composer corta o overflow.
 *
 * @param trigger Botão que o abriu, para posicionar o painel.
 * @param onInsert Recebe o shortcode, ex.: `:wave:`.
 */
export declare function openStickerPicker(trigger: HTMLElement | null, onInsert: (text: string) => void): Promise<void>;
export declare function close(): void;
/**
 * Hidrata os stickers animados de um nó, pausados até o hover. O observer da
 * extensão usaria a opção do admin, e com `hover_play` desligado a grade inteira
 * animaria junta. No stream vale a configuração; no picker é forçado.
 *
 * Os renderers deles pulam nós já inicializados, então rodar antes faz o observer
 * ignorá-los. Esses dois módulos, ao contrário do picker, registram no boot.
 */
export declare function playOnHover(node: HTMLElement): void;
export declare function isStickerPickerOpen(): boolean;
export declare function stickerIcon(): Mithril.Children;
/** Só faz sentido com a extensão presente. */
export declare function stickerLabel(): string;
