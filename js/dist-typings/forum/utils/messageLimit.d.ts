import type Channel from '../../common/models/Channel';
/**
 * Tamanho máximo de mensagem no canal. Compartilhado pelo contador, pela trava de
 * envio e pela dica do formulário, que não podem divergir. Nulo ou zero segue o
 * fórum, como `Channel::maxMessageLength()` no servidor.
 */
export declare function resolveMaxMessageLength(channel?: Channel | null): number;
/** Configuração do fórum, para canais que não a sobrescrevem. */
export declare function forumMaxMessageLength(): number;
