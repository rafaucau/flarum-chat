import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
export interface BookmarksListAttrs extends ComponentAttrs {
    state: ChatState;
}
/**
 * Mensagens salvas pelo usuário, de todos os canais.
 *
 * Linhas são resumos, não ChatMessage: responder, editar e fixar só fazem sentido
 * junto da conversa. Aqui cada linha leva à mensagem no canal.
 */
export default class BookmarksList extends Component<BookmarksListAttrs> {
    private messages;
    private loading;
    private working;
    oninit(vnode: Mithril.Vnode<BookmarksListAttrs>): void;
    view(): Mithril.Children;
    protected row(message: Message): Mithril.Children;
    protected excerpt(message: Message): string;
    protected load(): Promise<void>;
    /**
     * Abre o canal e, se houver, a thread: uma resposta de thread não está na janela
     * do canal.
     */
    protected open(message: Message): void;
    protected remove(message: Message): Promise<void>;
}
