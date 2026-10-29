import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
export interface ThreadPanelAttrs extends ComponentAttrs {
    channel: Channel;
    threadId: number;
    state: ChatState;
    /** Chamado ao fechar o painel, para a rota largar a thread. */
    onClose: () => void;
}
/**
 * Respostas de uma thread, ao lado do canal.
 *
 * O filtro de canal da API omite as respostas, então só aparecem aqui. É um
 * ChannelView com `filter[thread]` e sem divisor de não lidas: thread não tem
 * marcador de leitura próprio.
 */
export default class ThreadPanel extends Component<ThreadPanelAttrs> {
    private scroller;
    private pinned;
    private lastRenderedCount;
    oninit(vnode: Mithril.Vnode<ThreadPanelAttrs>): void;
    onbeforeupdate(vnode: Mithril.VnodeDOM<ThreadPanelAttrs, this>): void;
    oncreate(vnode: Mithril.VnodeDOM<ThreadPanelAttrs>): void;
    onupdate(): void;
    view(): Mithril.Children;
    protected load(): void;
    protected onScroll(e: Event): void;
    /** Resposta e edição ficam no escopo do painel, sem afetar o composer do canal. */
    protected reply(message: Message): void;
    protected edit(message: Message): void;
    protected onSent(): void;
    protected scrollToBottom(): void;
}
