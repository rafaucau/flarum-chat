import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
export interface ChatMessageAttrs extends ComponentAttrs {
    message: Message;
    /** Linha anterior, usada para agrupar. */
    previous?: Message | null;
    state: ChatState;
    onReply?: (message: Message) => void;
    onEdit?: (message: Message) => void;
    onOpenThread?: (message: Message) => void;
    /** Esconde a faixa "N respostas"; usado dentro do próprio painel da thread. */
    hideThreadIndicator?: boolean;
}
/**
 * Uma linha do stream. Agrupamento, destaque de menção e lápides vêm do model,
 * para uma linha vinda do realtime ficar igual a uma vinda de fetch.
 */
export default class ChatMessage extends Component<ChatMessageAttrs> {
    view(): Mithril.Children;
    /**
     * Mensagens apagadas mantêm o lugar no stream; removê-las deixaria as respostas
     * a elas sem sentido.
     */
    protected tombstone(message: Message): Mithril.Children;
    /**
     * Imagem da discussão anunciada, no topo do card. Só em anúncios do bot.
     *
     * A URL é filtrada de novo (só http/https) mesmo o servidor já filtrando: vai
     * parar num `src`.
     */
    protected announcementIcon(message: Message): Mithril.Children;
    /**
     * Remove a linha de vez. Fica na lápide, não na barra de hover, longe de
     * "responder" e "reagir", por ser irreversível.
     */
    protected purgeButton(message: Message): Mithril.Children;
    protected purge(message: Message): Promise<void>;
    protected content(message: Message): Mithril.Children;
    /**
     * Marcador "editada". `editedAt` pode faltar numa linha atualizada pelo socket
     * antes da API responder; aí mostra só a palavra.
     */
    protected editedMark(message: Message): Mithril.Children;
    protected systemRow(message: Message): Mithril.Children;
    /**
     * Abre o visualizador de imagens. Montado num nó do body: dentro do stream
     * (`overflow: auto`) o overlay seria cortado.
     */
    protected openLightbox(images: any[], index: number): Promise<void>;
    /**
     * Avatar com os badges de grupo. Usa `user.badges()` como o `PostUser`, então
     * extensões que estendem `User.prototype.badges` aparecem aqui também. O wrapper
     * só existe quando há badge.
     */
    protected avatar(message: Message): Mithril.Children;
    protected shortTime(message: Message): Mithril.Children;
    /**
     * Prévia da mensagem respondida. O clique pula para a original ou avisa se ela
     * está fora da janela carregada. É `button` para funcionar no teclado; o clique
     * no nome do autor segue para o perfil.
     */
    protected replyPreview(message: Message): Mithril.Children;
    protected reactions(message: Message): Mithril.Children;
    protected uploads(message: Message): Mithril.Children;
    protected threadIndicator(message: Message): Mithril.Children;
    protected actions(message: Message): Mithril.Children;
    protected react(message: Message, emoji: string): Promise<void>;
    protected bookmark(message: Message): Promise<void>;
    /**
     * Alterna a fixação, de forma otimista; numa falha volta ao estado do servidor.
     */
    protected pin(message: Message): Promise<void>;
    protected delete(message: Message): Promise<void>;
}
