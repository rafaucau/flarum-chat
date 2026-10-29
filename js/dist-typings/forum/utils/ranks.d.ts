import type Mithril from 'mithril';
import type { RankBook, RankEntry } from '../../common/models/Channel';
import type Message from '../../common/models/Message';
/**
 * Ranks de canal no cliente: qual rank cada pessoa exibe e como desenhá-lo.
 * Prioridade igual a Ramon\Chat\Rank\RankBook: dono, moderador, depois os ranks
 * do dono na ordem dele. Só funções puras: este módulo vai para mais de um chunk,
 * e estado aqui seria uma cópia por chunk.
 */
export declare const ICON_PATTERN: RegExp;
export declare function displayedKey(book: RankBook, userId: number): string | null;
export declare function displayedRank(book: RankBook, userId: number): RankEntry | null;
/** Ids dos ranks custom de um membro. */
export declare function heldRankIds(book: RankBook, userId: number): number[];
export declare function bookFor(channelId: number | string): RankBook | null;
/**
 * Rank antes do autor da mensagem. O livro do canal vence quando carregado, pois
 * o realtime o mantém atual; senão, o rank que o servidor anexou à mensagem.
 */
export declare function messageRank(message: Message): RankEntry | null;
/** Rótulo: o que o dono digitou, ou a tradução padrão de um rank embutido. */
export declare function rankName(rank: RankEntry): string;
export declare function validIcon(icon: string | null | undefined): string | null;
/**
 * A tag. Cor custom chega como custom properties lidas pelo LESS; rank embutido
 * na cor padrão não tem nenhuma e a classe usa a do tema.
 */
export declare function rankTag(rank: RankEntry, className?: string): Mithril.Children;
/**
 * Atributos do elemento em volta de um nome na cor do rank (rank sem tag). Vazio
 * para rank exibido como tag, ou sem rank.
 */
export declare function rankNameAttrs(rank: RankEntry | null): {
    className?: string;
    style?: Record<string, string>;
    title?: string;
};
