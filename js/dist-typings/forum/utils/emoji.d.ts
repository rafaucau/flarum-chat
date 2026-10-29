/**
 * Resolução de shortcode → emoji Unicode.
 *
 * Mapa local em vez do flarum/emoji: lá os dados ficam num chunk lazy que talvez
 * nunca carregue, então `flarum.reg.get()` quase sempre daria `undefined`. O
 * `simple-emoji-map` é o mesmo pacote que o flarum/emoji usa, então `:heart:` é igual
 * no chat e nos posts.
 *
 * Lazy porque são 55 KB de JSON; uma tabela pequena embutida cobre as reações comuns
 * para a primeira pintura nunca sair vazia.
 */
/**
 * Dispara o carregamento do chunk; só a primeira chamada busca. Redesenha ao
 * terminar para o que já foi renderizado com COMMON (ou como shortcode) atualizar.
 */
export declare function loadEmojiMap(): void;
/**
 * Verdadeiro quando a string já contém emoji (ou pictograma não ASCII). Frouxo de
 * propósito: só distingue "colou 💬" de "digitou speech_balloon".
 */
export declare function looksLikeEmoji(input: string): boolean;
/**
 * Resolve um shortcode, com ou sem dois-pontos. Devolve a entrada se já for emoji e
 * `null` se o shortcode for desconhecido; quem chama decide o fallback.
 */
export declare function resolveEmoji(input: string | null | undefined): string | null;
/** Emoji para exibição, com fallback para o shortcode cru em vez de ficar em branco. */
export declare function displayEmoji(input: string | null | undefined, fallback?: string): string;
export interface EmojiSuggestion {
    /** Shortcode canônico, sem dois-pontos. */
    name: string;
    unicode: string;
}
/**
 * Busca por shortcode para o picker. Prefixo vem antes de substring ("hea" mostra
 * `heart` antes de `broken_heart`). Resultados limitados: são ~1949 entradas
 * pontuadas a cada tecla.
 */
export declare function searchEmoji(query: string, limit?: number): EmojiSuggestion[];
/** Verdadeiro quando o mapa completo já carregou, para o picker indicar carregamento. */
export declare function emojiMapReady(): boolean;
/**
 * Se o valor serve como emoji de canal. Espelha a validação de ChannelResource para
 * o formulário recusar antes do request.
 */
export declare function isValidEmoji(input: string | null | undefined): boolean;
