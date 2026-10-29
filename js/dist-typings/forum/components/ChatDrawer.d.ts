import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
/**
 * Baixa o painel do drawer, que fica no chunk lazy da interface. Chamadas
 * simultâneas viram uma só.
 */
export declare function loadDrawerPanel(): Promise<void>;
/**
 * Casca do drawer que fica no bundle inicial. Fechado não desenha nada; aberto
 * antes do chunk chegar, mostra a caixa com spinner até o ChatDrawerPanel assumir.
 */
export default class ChatDrawer extends Component<ComponentAttrs> {
    /** Se a caixa provisória estava na tela no último render. */
    private placeholderShown;
    oncreate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    onupdate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void;
    /**
     * Sem o chunk, é a caixa provisória que cresce a partir do botão; o painel que a
     * substitui entra sem repetir a animação.
     */
    protected morphPlaceholder(dom: Element | null): void;
    view(): Mithril.Children;
    /**
     * Abre o drawer e carrega a lista de canais na primeira vez. O redraw vem antes
     * do carregamento para o drawer não esperar a ida e volta; a sidebar mostra o
     * skeleton enquanto isso.
     */
    static open(): Promise<void>;
}
