import app from 'flarum/forum/app';

import type Channel from '../../common/models/Channel';
import { adoptChannelPayload } from './invitations';

/**
 * Transferência de canal para outro membro, em três passos no servidor (ver
 * Service\OwnershipTransfers): o dono inicia e recebe um código por e-mail,
 * confirma o código, e o membro aceita ou recusa. Cada passo devolve o canal e os
 * membros, que vão para o store. O código não é guardado nem logado aqui.
 */
type TransferStep = '' | '/confirm' | '/cancel' | '/accept' | '/decline';

async function transferRequest(channelId: number | string, step: TransferStep, attributes: Record<string, unknown> = {}): Promise<Channel | null> {
  const payload = await app.request<any>({
    method: 'POST',
    url: `${app.forum.attribute('apiUrl')}/chat-channels/${channelId}/transfer${step}`,
    body: { data: { attributes } },
    // Quem chama mostra a resposta do servidor (código errado diz quantas
    // tentativas restam); o alerta padrão do core duplicaria a mensagem.
    errorHandler: () => undefined,
  });

  return adoptChannelPayload(payload);
}

export function startTransfer(channelId: number | string, userId: number): Promise<Channel | null> {
  return transferRequest(channelId, '', { userId });
}

export function confirmTransfer(channelId: number | string, code: string): Promise<Channel | null> {
  return transferRequest(channelId, '/confirm', { code });
}

export function cancelTransfer(channelId: number | string): Promise<Channel | null> {
  return transferRequest(channelId, '/cancel');
}

export function acceptTransfer(channelId: number | string): Promise<Channel | null> {
  return transferRequest(channelId, '/accept');
}

export function declineTransfer(channelId: number | string): Promise<Channel | null> {
  return transferRequest(channelId, '/decline');
}
