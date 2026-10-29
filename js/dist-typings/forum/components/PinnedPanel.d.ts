import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
export interface PinnedPanelAttrs extends ComponentAttrs {
    channel: Channel;
    state: ChatState;
    onClose: () => void;
    /**
     * true dentro do drawer, onde os painéis se empilham sem URL. Como no
     * ChannelView: rotear a partir do drawer o fecharia.
     */
    embedded?: boolean;
}
/**
 * Mensagens fixadas de um canal. Usa o mesmo espaço do painel de thread (nunca
 * abrem juntos). Fixar e desafixar ficam na própria linha da mensagem.
 */
export default class PinnedPanel extends Component<PinnedPanelAttrs> {
    private messages;
    private loading;
    oninit(vnode: Mithril.Vnode<PinnedPanelAttrs>): void;
    onbeforeupdate(vnode: Mithril.VnodeDOM<PinnedPanelAttrs, this>): void;
    view(): Mithril.Children;
    /**
     * Responder a uma fixada. O painel não tem composer: fecha e passa o contexto ao
     * do canal, ou ao da thread quando a mensagem está numa thread.
     */
    protected reply(message: Message): void;
    protected edit(message: Message): void;
    /** Fecha o painel, abre o composer certo e aplica a ação nele. */
    protected stage(message: Message, apply: (channelId: number, threadId: number | null) => void): void;
    protected load(): Promise<void>;
}
