import type Channel from '../../common/models/Channel';
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
export declare function acceptInvitation(channelId: number): Promise<Channel | null>;
export declare function declineInvitation(channelId: number): Promise<void>;
/**
 * Coloca um documento JSON:API de canal no store e devolve o registro. Usado por
 * todo endpoint que responde com um canal (join, accept, add members).
 */
export declare function adoptChannelPayload(payload: unknown): Channel | null;
/** Texto de erro da resposta do servidor, ou o fallback local quando não houver. */
export declare function invitationErrorText(error: any, fallbackKey: string): string;
