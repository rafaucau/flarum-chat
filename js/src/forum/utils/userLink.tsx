import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import username from 'flarum/common/helpers/username';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';

/**
 * Nome de usuário com link para o perfil. Autor apagado ou não carregado vira
 * texto simples: `app.route.user()` exige um registro real.
 */
export default function userLink(user: User | null | false | undefined): Mithril.Children {
  if (!user) return username(user as any);

  return (
    <Link className="ChatUserLink" href={app.route.user(user)}>
      {username(user)}
    </Link>
  );
}
