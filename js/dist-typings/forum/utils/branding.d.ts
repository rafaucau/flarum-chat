/**
 * Nome e ícone do chat configuráveis pelo admin, lidos pelo botão do header e
 * pela gaveta.
 */
/** Nome do chat; sem configuração, usa a tradução padrão. */
export declare function chatTitle(): string;
/**
 * Classe Font Awesome do ícone, ou null quando o admin o desligou. Null em vez de
 * string vazia obriga quem chama a decidir o que renderizar sem ícone.
 */
export declare function chatIcon(): string | null;
