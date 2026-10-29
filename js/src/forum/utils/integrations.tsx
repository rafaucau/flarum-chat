import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';

/**
 * Componentes de extensões irmãs, resolvidos em runtime. Um `import` estático de
 * `ext:ramon-verified/…` tornaria a extensão obrigatória e quebraria o boot do
 * chat sem ela; pelo registro de exports do Flarum o recurso só não aparece.
 *
 * @see utils/stickers.tsx — mesma abordagem para ramon/stickers.
 */

function fromRegistry(extension: string, path: string): any {
  const registry = (window as any)?.flarum?.reg;

  if (!registry) return null;

  try {
    // `checkModule`, não `get`: `get` loga "No module found" a cada falha e lança
    // com `flarum.debug` ligado.
    const module = typeof registry.checkModule === 'function' ? registry.checkModule(extension, path) : registry.get?.(extension, path);

    if (!module) return null;

    return module.default ?? module ?? null;
  } catch {
    return null;
  }
}

/**
 * Selo de verificado ao lado do nome do autor, na mesma posição que o
 * ramon/verified usa no post. Null sem a extensão, sem verificação ou sem autor
 * (o bot não tem usuário).
 */
// `hasOne` retorna `false` quando a relação não foi carregada, daí o `false` no tipo.
export function verifiedBadge(user: User | null | undefined | false): Mithril.Children {
  if (!user) return null;

  // O atributo vem do model extender do ramon/verified; sem ele o método não existe.
  const isVerified = (user as any).isVerified;

  if (typeof isVerified !== 'function' || !isVerified.call(user)) return null;

  const Badge = fromRegistry('ramon-verified', 'common/components/VerifiedBadge');

  if (!Badge) return null;

  return m(Badge, { user, className: 'VerifiedBadge--chat' });
}
