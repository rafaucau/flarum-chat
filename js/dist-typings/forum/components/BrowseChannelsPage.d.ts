import Page from 'flarum/common/components/Page';
import type { IPageAttrs } from 'flarum/common/components/Page';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
type BrowseFilter = 'all' | 'open' | 'closed' | 'archived' | 'mine';
/**
 * Página "Explorar canais": todos os canais visíveis, com botões de entrar e, para
 * quem pode, de criar. É o único lugar onde se cria canal, por isso a sidebar
 * aponta para cá mesmo sem canais.
 */
export default class BrowseChannelsPage<CustomAttrs extends IPageAttrs = IPageAttrs> extends Page<CustomAttrs> {
    private channels;
    private loading;
    private filter;
    private query;
    private searchTimer;
    oninit(vnode: Mithril.Vnode<CustomAttrs>): void;
    view(): Mithril.Children;
    /**
     * Estado vazio. Fórum sem canais oferece criar um; busca sem resultado oferece
     * voltar à lista completa.
     */
    protected empty(): Mithril.Children;
    protected card(channel: Channel): Mithril.Children;
    /**
     * Botão redondo só com ícone. O label serve de tooltip e de nome acessível.
     */
    protected iconButton(icon: string, labelKey: string, onclick: () => void, danger?: boolean): Mithril.Children;
    protected setFilter(filter: BrowseFilter): void;
    /** Com debounce, para não disparar uma requisição por tecla. */
    protected onSearch(value: string): void;
    /** Volta à lista sem filtro, sem esperar o debounce. */
    protected clearSearch(): void;
    protected load(): Promise<void>;
    protected create(): void;
    protected edit(channel: Channel): void;
    /**
     * Entra no canal ou, com `hidden`, inspeciona: sem aparecer na lista de membros
     * nem notificar. O servidor exige `inspectChannels` para o modo oculto.
     */
    protected join(channel: Channel, hidden?: boolean): Promise<void>;
    /**
     * Aceita ou recusa um convite pelo card. Recusar deixa o canal disponível para
     * entrar por conta própria.
     */
    protected answerInvitation(channel: Channel, accept: boolean): Promise<void>;
    protected open(channel: Channel): void;
}
export {};
