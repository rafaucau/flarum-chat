import type User from 'flarum/common/models/User';

/** Mesma janela do indicador de online do core, para o chat não discordar do fórum. */
const ONLINE_WINDOW_MS = 5 * 60 * 1000;

/**
 * Online a partir de `lastSeenAt`: o flarum/realtime só expõe o canal `public` e
 * um privado por usuário, sem canal de presença. É o mesmo sinal do core, com a
 * mesma imprecisão de alguns minutos.
 */
export function isOnline(user: User | null | undefined): boolean {
  if (!user) return false;

  // Quem esconde a atividade recebe o atributo nulo do core; o chat não pode revelar.
  const seen = user.lastSeenAt();

  if (!seen) return false;

  return Date.now() - seen.getTime() < ONLINE_WINDOW_MS;
}
