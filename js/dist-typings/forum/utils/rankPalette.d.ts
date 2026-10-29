/**
 * Espelho de Ramon\Chat\Rank\RankPalette para a prévia do editor de ranks: o
 * servidor já envia as cores dos ranks salvos, isto só acompanha a cor enquanto
 * é escolhida. Mudou lá, mude aqui.
 */
export declare const LIGHT_SURFACE = "#ffffff";
export declare const DARK_SURFACE = "#222226";
export interface RankColors {
    textColor: string | null;
    nameLight: string | null;
    nameDark: string | null;
}
export declare function isHex(value: unknown): value is string;
export declare function luminance(hex: string): number;
export declare function contrast(a: string, b: string): number;
export declare function deriveRankColors(hex: string | null): RankColors;
