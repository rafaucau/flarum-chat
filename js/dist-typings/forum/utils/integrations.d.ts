import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
/**
 * Selo de verificado ao lado do nome do autor, na mesma posição que o
 * ramon/verified usa no post. Null sem a extensão, sem verificação ou sem autor
 * (o bot não tem usuário).
 */
export declare function verifiedBadge(user: User | null | undefined | false): Mithril.Children;
