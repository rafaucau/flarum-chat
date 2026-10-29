import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Message from '../../common/models/Message';
import type MessageFlag from '../../common/models/MessageFlag';
import type ChatState from '../state/ChatState';
export interface FlaggedMessagesListAttrs extends ComponentAttrs {
    state: ChatState;
}
/**
 * Fila de moderação do chat.
 *
 * Não usa flarum/flags: `flags.post_id` é FK não nula para `posts`. Linhas são
 * resumos, como em BookmarksList.
 */
export default class FlaggedMessagesList extends Component<FlaggedMessagesListAttrs> {
    private flags;
    private loading;
    /** Denúncia com requisição em andamento, para só essa linha mostrar spinner. */
    private working;
    /** Mostra também as denúncias resolvidas. */
    private showResolved;
    oninit(vnode: Mithril.Vnode<FlaggedMessagesListAttrs>): void;
    view(): Mithril.Children;
    protected body(): Mithril.Children;
    protected row(flag: MessageFlag): Mithril.Children;
    protected target(message: Message): Mithril.Children;
    protected load(): Promise<void>;
    /**
     * Abre o canal e, se houver, a thread: uma resposta de thread não está na janela
     * do canal.
     */
    protected open(message: Message): void;
    protected resolve(flag: MessageFlag): Promise<void>;
    /**
     * Apaga a mensagem denunciada; o servidor fecha junto todas as denúncias abertas
     * dela (ResolveFlagsOnModeration).
     */
    protected deleteMessage(flag: MessageFlag, message: Message): Promise<void>;
    /**
     * Remove a linha da fila aberta, ou a marca no lugar quando as resolvidas estão
     * visíveis.
     */
    protected afterResolved(flag: MessageFlag): void;
}
