import app from 'flarum/forum/app';

/**
 * Nome e ícone do chat configuráveis pelo admin, lidos pelo botão do header e
 * pela gaveta.
 */

/** Nome do chat; sem configuração, usa a tradução padrão. */
export function chatTitle(): string {
  const custom = (app.forum.attribute<string>('ramon-chat.title') ?? '').trim();

  if (custom !== '') return custom;

  return app.translator.trans('ramon-chat.forum.nav.chat', {}, true);
}

/**
 * Classe Font Awesome do ícone, ou null quando o admin o desligou. Null em vez de
 * string vazia obriga quem chama a decidir o que renderizar sem ícone.
 */
export function chatIcon(): string | null {
  if (app.forum.attribute<boolean>('ramon-chat.showIcon') === false) return null;

  const icon = (app.forum.attribute<string>('ramon-chat.icon') ?? '').trim();

  return icon === '' ? 'fas fa-comments' : icon;
}
