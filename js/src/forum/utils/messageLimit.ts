import app from 'flarum/forum/app';

import type Channel from '../../common/models/Channel';

/** Usado quando o fórum não tem configuração própria; igual ao padrão do servidor. */
const FALLBACK = 3000;

/**
 * Tamanho máximo de mensagem no canal. Compartilhado pelo contador, pela trava de
 * envio e pela dica do formulário, que não podem divergir. Nulo ou zero segue o
 * fórum, como `Channel::maxMessageLength()` no servidor.
 */
export function resolveMaxMessageLength(channel?: Channel | null): number {
  const own = channel?.maxMessageLength?.();
  const forum = forumMaxMessageLength();

  // O canal só encurta o limite: o do fórum é o teto.
  if (own && own > 0) return Math.min(own, forum);

  return forum;
}

/** Configuração do fórum, para canais que não a sobrescrevem. */
export function forumMaxMessageLength(): number {
  const forum = Number(app.forum.attribute<number>('ramon-chat.maxMessageLength') ?? FALLBACK);

  return forum > 0 ? forum : FALLBACK;
}
