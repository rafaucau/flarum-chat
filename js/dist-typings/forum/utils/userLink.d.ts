import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
/**
 * Nome de usuário com link para o perfil. Autor apagado ou não carregado vira
 * texto simples: `app.route.user()` exige um registro real.
 */
export default function userLink(user: User | null | false | undefined): Mithril.Children;
