import type User from 'flarum/common/models/User';
/**
 * Online a partir de `lastSeenAt`: o flarum/realtime só expõe o canal `public` e
 * um privado por usuário, sem canal de presença. É o mesmo sinal do core, com a
 * mesma imprecisão de alguns minutos.
 */
export declare function isOnline(user: User | null | undefined): boolean;
