import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
import type ChatState from '../state/ChatState';
export interface ChatSidebarAttrs extends ComponentAttrs {
    state: ChatState;
    onSelect?: (channel: Channel) => void;
}
/** An icon button in a section header. */
interface SectionAction {
    icon: string;
    title: string;
    action: () => void;
}
/** Navegação: Minhas threads, Busca, depois Canais e Mensagens diretas. */
export default class ChatSidebar extends Component<ChatSidebarAttrs> {
    /** Timer de intenção de hover pendente (ver handlers da linha do canal). */
    private prefetchTimer;
    oncreate(vnode: Mithril.VnodeDOM<ChatSidebarAttrs, this>): void;
    onupdate(vnode: Mithril.VnodeDOM<ChatSidebarAttrs, this>): void;
    onremove(vnode: Mithril.VnodeDOM<ChatSidebarAttrs, this>): void;
    /**
     * Pré-carrega os não lidos (menções primeiro) com o navegador ocioso. Só com o
     * websocket ativo, que mantém atualizado o stream carregado em segundo plano.
     */
    protected warmUnread(): void;
    view(): Mithril.Children;
    /** Um dos atalhos acima da lista de canais, com estado ativo. */
    protected quickLink(routeName: string, icon: string, key: string, badge?: number): Mithril.Children;
    /** Grupo de canais com título e ações (explorar, criar). */
    protected section(labelKey: string, channels: Channel[], actions: SectionAction[]): Mithril.Children;
    protected row(channel: Channel): Mithril.Children;
    /**
     * Pré-carrega o canal quando o ponteiro para na linha. Substitui o timer
     * pendente da linha anterior.
     */
    protected schedulePrefetch(channel: Channel): void;
    protected cancelPrefetch(): void;
    protected createChannel(): void;
}
export {};
