import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
export interface ChannelViewAttrs extends ComponentAttrs {
    channel: Channel;
    state: ChatState;
    /** Botão de voltar no cabeçalho, usado só pelo drawer. */
    onBack?: () => void;
    /**
     * Dentro do drawer, que mostra o painel de thread por conta própria. Abrir uma
     * thread só ajusta o state; rotear fecharia o drawer.
     */
    embedded?: boolean;
}
/**
 * Cabeçalho, stream e composer de um canal.
 *
 * O stream é ancorado no fim: segue a mensagem mais nova enquanto o usuário está
 * no fim e mantém a posição quando não está. A ancoragem é explícita, sem
 * depender do navegador.
 */
export default class ChannelView extends Component<ChannelViewAttrs> {
    private scroller;
    /** Se o usuário está no fim do stream (ou perto). */
    private pinned;
    /** Altura antes de inserir mensagens antigas, para restaurar a posição. */
    private heightBeforePrepend;
    private lastRenderedCount;
    oninit(vnode: Mithril.Vnode<ChannelViewAttrs>): void;
    onbeforeupdate(vnode: Mithril.VnodeDOM<ChannelViewAttrs, this>): void;
    oncreate(vnode: Mithril.VnodeDOM<ChannelViewAttrs>): void;
    onupdate(vnode: Mithril.VnodeDOM<ChannelViewAttrs>): void;
    view(): Mithril.Children;
    /**
     * Faixa da mensagem fixada sob o cabeçalho, também no drawer. O clique pula
     * para a mensagem, carregando o histórico até ela se preciso; uma fixada que o
     * stream do canal não mostra (resposta de thread) abre o painel de fixadas.
     */
    protected pinnedBar(): Mithril.Children;
    protected jumpToPinned(pinned: Message): void;
    /**
     * Barra do canal: ícone, nome, descrição, ações. Não aparece com embedded: o
     * cabeçalho do drawer já faz esse papel.
     */
    protected header(): Mithril.Children;
    /** Intercala separadores de data e o divisor de não lidas com as mensagens. */
    protected rows(messages: Message[], dividerAfterId: number | null): Mithril.Children;
    protected dateLabel(date: Date): string;
    protected typingIndicator(): Mithril.Children;
    protected skeleton(): Mithril.Children;
    protected load(): Promise<void>;
    protected onScroll(e: Event): void;
    /**
     * Botão de voltar ao fim. Segue `pinned`, que já decide se o stream acompanha as
     * novas mensagens, então aparece exatamente quando não acompanha.
     */
    protected scrollDownButton(): Mithril.Children;
    /**
     * Pula para a mensagem mais nova, sem animação: o `onupdate` interrompe a
     * animação ao reancorar, e o `scrollTo` suave calcula o alvo uma vez só e para
     * antes do fim se linhas ainda estão sendo montadas.
     */
    protected scrollToBottom(): void;
    protected onSent(): void;
    protected reply(message: Message): void;
    protected edit(message: Message): void;
    protected openThread(message: Message): void;
}
