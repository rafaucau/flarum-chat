import type Channel from '../../common/models/Channel';
import type ChatState from '../state/ChatState';
/**
 * Uma ação do ator sobre o canal, a partir do header. Descritor em vez de controle
 * renderizado: o header do canal desenha botões de ícone e a gaveta os põe num menu
 * `⋯` com rótulo, e as duas superfícies usam o mesmo conjunto.
 */
export interface ChannelAction {
    key: string;
    icon: string;
    /** Já traduzido: é o title do botão numa superfície e o texto na outra. */
    label: string;
    /** Desenhado pressionado: o painel de fixadas é um toggle. */
    active?: boolean;
    loading?: boolean;
    onclick: () => void;
}
export interface ChannelActionOptions {
    /**
     * A superfície não tem rota própria (a gaveta). Ações que navegariam abrem no
     * lugar: o ChatPage fecha a gaveta ao chegar, tirando o leitor da janela.
     */
    embedded?: boolean;
}
/**
 * Tudo o que o ator pode fazer no canal, na ordem das duas superfícies. Cada item
 * depende de uma flag calculada no servidor (ver ChannelPolicy), então o controle
 * some em vez de ser recusado.
 */
export declare function channelActions(channel: Channel, state: ChatState, options?: ChannelActionOptions): ChannelAction[];
/**
 * Detalhes do canal: nível de notificação, membros e ações de estado permitidas.
 * Disponível a todo membro, ao contrário do formulário de configurações.
 */
export declare function openChannelInfo(channel: Channel): void;
