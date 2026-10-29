import Page from 'flarum/common/components/Page';
import type { IPageAttrs } from 'flarum/common/components/Page';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
/**
 * Chat em tela cheia. No desktop, sidebar e canal lado a lado; no celular, um ou
 * outro.
 */
export default class ChatPage<CustomAttrs extends IPageAttrs = IPageAttrs> extends Page<CustomAttrs> {
    /**
     * Se ainda não há nada a desenhar. Vem do state: a lista de canais é buscada uma
     * vez por sessão e o realtime a mantém atualizada, então nas visitas seguintes
     * não há skeleton.
     */
    private loading;
    /** Última entrada passada ao `app.history` (ver `recordHistory`). */
    private historyKey;
    /**
     * Classe no `<body>` enquanto a página está montada, para o CSS esconder o que o
     * fórum desenha em volta. O `Page.bodyClass` do core usa `#app`, mas algumas
     * extensões (ex.: rodapés) montam elementos irmãos de `#app` no body.
     */
    protected static readonly BODY_CLASS = "ChatFullPage";
    oncreate(vnode: Mithril.VnodeDOM<CustomAttrs, this>): void;
    /**
     * Ao sair com um drawer para restaurar, ele cresce a partir da página (o
     * inverso da entrada).
     */
    onbeforeremove(vnode: Mithril.VnodeDOM<CustomAttrs, this>): void;
    oninit(vnode: Mithril.Vnode<CustomAttrs>): void;
    /**
     * Dispara ao sair do chat para o fórum, não entre rotas do chat, que dividem a
     * mesma página montada (ChatPageResolver).
     */
    onremove(vnode: Mithril.VnodeDOM<CustomAttrs, this>): void;
    onbeforeupdate(vnode: Mithril.VnodeDOM<CustomAttrs, this>): void;
    /**
     * Registra a rota atual no histórico do core, o que dá a seta de voltar na
     * toolbar do celular (`Navigation` só a mostra com `app.history.canGoBack()`).
     *
     * Nomes distintos para lista, canal e seção: `History#push` substitui entradas
     * de mesmo nome, e assim voltar segue canal → lista → fórum. Chamado também no
     * `onbeforeupdate` porque as rotas do chat dividem uma página montada; a key
     * evita um push por redraw.
     */
    protected recordHistory(): void;
    /**
     * Alinha canal e thread ativos com a URL. As rotas dividem uma página montada,
     * então não dá para ler a rota só no `oninit`. Cobre voltar/avançar do
     * navegador e links de fora; a navegação interna já ajusta o state antes.
     */
    protected syncFromRoute(): void;
    view(): Mithril.Children;
    /**
     * Se a rota é uma seção da sidebar, não um canal. Seções ocupam o painel
     * principal e não têm cabeçalho, então no celular precisam de voltar na toolbar.
     */
    protected static isSectionRoute(routeName: string | undefined): boolean;
    /**
     * Título da toolbar do celular, pela rota (ver `mobileTitleControl`). Reusa as
     * traduções da sidebar.
     */
    protected toolbarTitle(routeName: string | undefined): string;
    protected mainPane(routeName: string | undefined, channel: Channel | null, narrow: boolean, threadId: number | null): Mithril.Children;
    protected empty(): Mithril.Children;
    protected boot(): Promise<void>;
    protected select(channel: Channel): void;
    protected deselect(): void;
    /** Fechar o painel tira a thread da URL, para recarregar não reabri-la. */
    protected closeThread(channel: Channel): void;
}
