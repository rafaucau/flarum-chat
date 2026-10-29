import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
/**
 * Painel flutuante do chat, no canto inferior direito de qualquer página.
 *
 * Montado uma vez na raiz do app, para navegar pelo fórum não fechar a conversa.
 * Em telas estreitas vira um botão flutuante que abre a página cheia (ver
 * `shouldUseChatDrawer`). Fica no chunk lazy; o ChatDrawer é a casca que o monta.
 */
export default class ChatDrawerPanel extends Component<ComponentAttrs> {
    /** Último `isNarrowViewport()`, para o resize só redesenhar quando muda. */
    private narrow;
    /**
     * A escolha entre painel e botão é feita em `view()`, não por media query; sem
     * isto uma rotação manteria o formato antigo até outro redraw.
     */
    private onResize;
    /** Se a caixa do drawer estava na tela no último render. */
    private shown;
    oncreate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    onupdate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    /**
     * Ao aparecer, o drawer cresce a partir de quem o abriu (botão do cabeçalho ou
     * página cheia). Sem origem recente não faz nada (ver utils/morph).
     */
    protected morphWhenShown(dom: Element | null): void;
    onremove(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    view(): Mithril.Children;
    /**
     * Clicar na barra recolhe o drawer, a menos que o clique seja num controle.
     *
     * Verifica o alvo em vez de usar `stopPropagation`: o Bootstrap abre dropdowns
     * por um handler delegado no `document`. `.Dropdown` entra na lista porque o
     * padding do menu não é botão.
     */
    protected onHeaderClick(e: Event): void;
    /**
     * Menu de ações do canal. O wrapper não pode ter handler de clique: o Bootstrap
     * abre o dropdown por handler delegado no `document`, e um `stopPropagation` no
     * caminho o impediria (ver `onHeaderClick`).
     */
    protected channelMenu(channel: Channel): Mithril.Children;
    /**
     * O drawer em telas estreitas: um botão no canto que abre o chat em tela cheia,
     * sem cobrir o fórum. Tem um X porque `drawerOpen` sobrevive a recargas e o
     * botão do cabeçalho só abre o chat.
     */
    protected floatingButton(): Mithril.Children;
    /**
     * Conteúdo do drawer: a conversa ou a lista de canais, mais o painel sobreposto.
     *
     * Array, não fragment com slot condicional: o Mithril exige que todos os filhos
     * tenham key se o primeiro tiver, e conta `null` como sem key.
     */
    protected body(channel: Channel | null): Mithril.Children;
    protected select(channel: Channel): void;
    protected toggleCollapsed(): void;
    /**
     * Única forma de fechar o drawer; navegar, recarregar e recolher o mantêm aberto.
     */
    protected close(): void;
    /**
     * Passa o canal atual para a página cheia. O drawer fica suspenso, não fechado:
     * ao sair da página ele volta (ChatPage.onremove).
     */
    protected goFullScreen(): void;
}
