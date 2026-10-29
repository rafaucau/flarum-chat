import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
export interface ChatSearchAttrs extends ComponentAttrs {
    state: ChatState;
    /** Se definido, restringe a busca a um canal. */
    channelId?: number | null;
    /**
     * Desenhado sobre a conversa no drawer, que não tem rota para `chat.search`.
     * Ganha cabeçalho de volta e abrir um resultado troca o canal no lugar.
     */
    embedded?: boolean;
    /** Fecha o painel. Obrigatório com embedded; ignorado sem. */
    onClose?: () => void;
}
/**
 * Busca de mensagens em um canal ou em tudo que o usuário pode ler. Usa
 * `filter[q]`, que já aplica o mesmo escopo de visibilidade do stream.
 */
export default class ChatSearch extends Component<ChatSearchAttrs> {
    private query;
    private results;
    private searching;
    /** Distingue "sem resultados" de "nada digitado". */
    private searched;
    private timer;
    /** Impede que uma requisição antiga e lenta sobrescreva uma mais nova. */
    private sequence;
    onremove(): void;
    view(): Mithril.Children;
    protected body(): Mithril.Children;
    protected result(message: Message): Mithril.Children;
    protected onInput(value: string): void;
    protected run(): Promise<void>;
    protected clear(): void;
    /**
     * Abre o canal do resultado, sem pular para a mensagem: o stream pagina a partir
     * da mais nova, e chegar numa antiga exigiria carregar tudo no meio.
     */
    protected open(message: Message): void;
}
