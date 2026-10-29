import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
import type { RankBook, RankEntry } from '../../common/models/Channel';
/** Espelha ChannelRank::MAX_PER_CHANNEL e ChannelRank::NAME_MAX. */
export declare const MAX_RANKS = 20;
export declare const RANK_NAME_MAX = 32;
type RankPath = '' | '/update' | '/delete' | '/order' | '/assign';
/**
 * Grava um rank; a resposta traz o canal, e o novo `rankBook` vai para o registro
 * do store que as linhas de autor e a aba de membros leem.
 */
export declare function rankRequest(channelId: number | string, path: RankPath, attributes: Record<string, unknown>): Promise<Channel | null>;
interface RankDraft {
    /** 'new', o nome de um rank nativo ou o id de um rank próprio. */
    target: 'new' | 'owner' | 'moderator' | number;
    builtin: 'owner' | 'moderator' | null;
    name: string;
    color: string;
    /** Rank nativo com a cor do tema. */
    themeColor: boolean;
    icon: string;
    showBadge: boolean;
}
export interface ChannelRanksTabAttrs extends ComponentAttrs {
    channel: Channel;
}
/**
 * Editor de ranks: os dois nativos e os próprios do canal, em ordem de prioridade.
 * A atribuição fica na aba de membros. Lê só o `rankBook` do canal, que toda
 * gravação devolve atualizado.
 */
export default class ChannelRanksTab extends Component<ChannelRanksTabAttrs> {
    private draft;
    private working;
    private loading;
    oninit(vnode: Mithril.Vnode<ChannelRanksTabAttrs, this>): void;
    view(): Mithril.Children;
    protected book(): RankBook | null;
    protected load(): Promise<void>;
    protected row(book: RankBook, rank: RankEntry, custom: RankEntry[]): Mithril.Children;
    protected holders(book: RankBook, rank: RankEntry): number;
    protected edit(rank: RankEntry | null): void;
    protected editor(draft: RankDraft): Mithril.Children;
    /** Prévia do rank no tema claro e no escuro, lado a lado. */
    protected preview(draft: RankDraft): Mithril.Children;
    protected save(draft: RankDraft): Promise<void>;
    protected move(custom: RankEntry[], index: number, by: number): Promise<void>;
    protected remove(rank: RankEntry): Promise<void>;
    protected run(path: RankPath, attributes: Record<string, unknown>, successKey: string | null, after?: () => void): Promise<void>;
}
export {};
