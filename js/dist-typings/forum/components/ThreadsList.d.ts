import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Thread from '../../common/models/Thread';
import type ChatState from '../state/ChatState';
export interface ThreadsListAttrs extends ComponentAttrs {
    state: ChatState;
    /** Todas as threads de um canal, em vez das do usuário em todos os canais. */
    channelId?: number | null;
    /** Desenhado sobre a conversa no drawer, com cabeçalho próprio. */
    embedded?: boolean;
    /** Fecha o painel. Obrigatório com embedded; ignorado sem. */
    onClose?: () => void;
}
/**
 * Threads do usuário em todos os canais, ou todas as de um canal. Na página a
 * linha abre a thread por rota; no drawer, pelo state, porque rotear fecha o drawer.
 */
export default class ThreadsList extends Component<ThreadsListAttrs> {
    private threads;
    private loading;
    oninit(vnode: Mithril.Vnode<ThreadsListAttrs>): void;
    onbeforeupdate(vnode: Mithril.VnodeDOM<ThreadsListAttrs, this>): void;
    view(): Mithril.Children;
    /**
     * Título e, no drawer, volta para a conversa. Na página só a lista de um canal
     * tem título; as seções já o mostram na toolbar.
     */
    protected header(): Mithril.Children;
    protected body(): Mithril.Children;
    protected row(thread: Thread): Mithril.Children;
    protected load(): Promise<void>;
    protected open(thread: Thread): void;
}
