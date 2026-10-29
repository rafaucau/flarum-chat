import app from 'flarum/forum/app';
import Avatar from 'flarum/common/components/Avatar';
import username from 'flarum/common/helpers/username';
import type Mithril from 'mithril';

import type Message from '../../common/models/Message';
import userLink from './userLink';

/**
 * Identidade do bot do chat. Não há conta por trás, de propósito: uma conta
 * apareceria em listas de membros e menções e poderia ser logada. O bot é só um
 * rótulo de autor, definido por configurações.
 */

/** Sem configuração, usa o nome do fórum. */
export function botName(): string {
  const configured = app.forum.attribute<string | null>('ramon-chat.botName');

  if (typeof configured === 'string' && configured.trim() !== '') {
    return configured.trim();
  }

  return app.translator.trans('ramon-chat.forum.bot.default_name', {}, true);
}

/**
 * Arquivo enviado vence a URL digitada. Os dois são guardados: removendo o upload,
 * a URL externa volta.
 */
export function botAvatarUrl(): string | null {
  const uploaded = app.forum.attribute<string | null>('ramon-chat.botAvatarPath');

  if (typeof uploaded === 'string' && uploaded.trim() !== '') {
    return `${app.forum.attribute('assetsBaseUrl')}/${uploaded.trim()}`;
  }

  const url = app.forum.attribute<string | null>('ramon-chat.botAvatarUrl');

  return typeof url === 'string' && url.trim() !== '' ? url.trim() : null;
}

/**
 * Avatar no formato do core, para ocupar a mesma calha. Sem imagem, usa a inicial
 * num disco colorido, como o core faz com usuário sem avatar.
 */
export function botAvatar(className = 'Avatar'): Mithril.Children {
  const url = botAvatarUrl();
  const name = botName();

  if (url) {
    return <img className={className} src={url} alt={name} loading="lazy" />;
  }

  return (
    <span className={`${className} Avatar--bot`} aria-label={name}>
      {name.charAt(0).toUpperCase()}
    </span>
  );
}

/**
 * Avatar de uma mensagem, de qualquer autor. Mensagem do bot não tem `user_id`,
 * então renderizar `message.user()` direto a mostraria como conta apagada.
 */
export function authorAvatar(message: Message, className = 'Avatar'): Mithril.Children {
  return message.isBot() ? botAvatar(className) : <Avatar user={message.user()} className={className} />;
}

/** Nome do autor em texto simples, para resumos e linhas curtas. */
export function authorName(message: Message): Mithril.Children {
  return message.isBot() ? botName() : username(message.user());
}

/** Nome do autor com link para o perfil; o bot não tem perfil. */
export function authorLink(message: Message): Mithril.Children {
  return message.isBot() ? botName() : userLink(message.user());
}
