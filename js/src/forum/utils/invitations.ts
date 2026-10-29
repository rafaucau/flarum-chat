import app from 'flarum/forum/app';

import type Channel from '../../common/models/Channel';
import chatState from '../state/chat';

/**
 * Resposta a convites de canal, compartilhada pela notificação, pelo composer do
 * canal público convidado, pelo card de navegação e pelo menu do canal.
 *
 * Aceitar usa a rota de convite, não `join`: canal privado não é visível para quem
 * não entrou, então o endpoint de join nunca o encontraria.
 */

/**
 * Aceita e devolve o canal como o servidor o serializa agora, ou null se o
 * convite já não estava aberto. O registro já traz `canPostMessage` e as demais
 * flags, então o composer aparece sem recarregar.
 */
export async function acceptInvitation(channelId: number): Promise<Channel | null> {
  const payload = await app.request<any>({
    method: 'POST',
    url: `${app.forum.attribute('apiUrl')}/chat/invites/${channelId}/accept`,
  });

  const channel = adoptChannelPayload(payload);

  if (channel) chatState.rememberChannel(channel);

  return channel;
}

export async function declineInvitation(channelId: number): Promise<void> {
  await app.request({
    method: 'POST',
    url: `${app.forum.attribute('apiUrl')}/chat/invites/${channelId}/decline`,
  });

  const channel = chatState.channel(channelId);

  // Canal público continua visível após recusar; só deixa de indicar convite aberto.
  if (channel) {
    channel.pushAttributes({
      isInvited: false,
      invitedById: null,
      invitedByName: null,
    });
  }
}

/**
 * Coloca um documento JSON:API de canal no store e devolve o registro. Usado por
 * todo endpoint que responde com um canal (join, accept, add members).
 */
export function adoptChannelPayload(payload: unknown): Channel | null {
  const document = payload as { data?: { id?: string } } | null;

  if (!document?.data?.id) return null;

  const pushed = app.store.pushPayload(document as any) as unknown as Channel | Channel[] | null;

  return (Array.isArray(pushed) ? pushed[0] : pushed) ?? null;
}

/** Texto de erro da resposta do servidor, ou o fallback local quando não houver. */
export function invitationErrorText(error: any, fallbackKey: string): string {
  const detail = error?.response?.errors?.[0]?.detail;

  if (typeof detail === 'string' && detail !== '') return detail;

  return app.translator.trans(fallbackKey, {}, true) as string;
}
