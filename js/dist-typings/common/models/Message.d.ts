import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Channel from './Channel';
import type { RankEntry } from './Channel';
import type Thread from './Thread';
import type Upload from './Upload';
/**
 * Agregado no servidor (MessageResource::reactionSummary()) para o cliente não
 * receber cada reação.
 */
export interface ReactionSummaryEntry {
    count: number;
    reacted: boolean;
}
export type ReactionSummary = Record<string, ReactionSummaryEntry>;
export default class Message extends Model {
    /** Texto-fonte. Null em mensagem apagada que o usuário não pode ver. */
    content: () => string | null;
    contentHtml: () => string | null;
    type: () => string;
    systemKey: () => string | null;
    systemData: () => Record<string, unknown> | null;
    number: () => number | null;
    channelId: () => number;
    threadId: () => number | null;
    replyToId: () => number | null;
    createdAt: () => Date | null | undefined;
    editedAt: () => Date | null | undefined;
    deletedAt: () => Date | null | undefined;
    /**
     * Avança a cada mudança (edição, exclusão, fixar, reação). O polling de
     * fallback se reconcilia por ele (pollChanges() em forum/index.tsx).
     */
    updatedAt: () => Date | null | undefined;
    isDeleted: () => boolean;
    /**
     * Apagada por alguém que não o autor. `isRedacted()` só diz que o texto foi
     * ocultado deste leitor, o que vale também para autoexclusão.
     */
    isModeratorDeleted: () => boolean;
    isEdited: () => boolean;
    isPinned: () => boolean;
    pinnedAt: () => Date | null | undefined;
    reactionSummary: () => ReactionSummary;
    mentionedUsers: () => number[];
    mentionsChannelWide: () => boolean;
    isBookmarked: () => boolean;
    /**
     * Rank do autor no momento da leitura. O `rankBook` do canal, mantido em dia
     * pelo realtime, tem precedência quando carregado.
     */
    authorRank: () => RankEntry | null;
    /** Se *este* leitor tem denúncia aberta contra ela. */
    isFlagged: () => boolean;
    /**
     * Só para quem tem `ramon-chat.moderate`; para os demais é undefined, não
     * zero, para não revelar quais mensagens foram denunciadas.
     */
    flagsCount: () => number | undefined;
    canEdit: () => boolean;
    canDelete: () => boolean;
    canReact: () => boolean;
    canReply: () => boolean;
    canCreateThread: () => boolean;
    canMove: () => boolean;
    canPin: () => boolean;
    canFlag: () => boolean;
    /** Remover a linha de vez, sem deixar tombstone. */
    canForceDelete: () => boolean;
    user: () => false | User | null;
    editedBy: () => false | User | null;
    deletedBy: () => false | User | null;
    replyTo: () => false | Message | null;
    thread: () => false | Thread | null;
    channel: () => false | Channel | null;
    uploads: () => false | (Upload | undefined)[];
    isSystem(): boolean;
    /**
     * Não é mensagem de sistema: renderiza como mensagem comum, mas sem registro
     * de usuário; nome e avatar vêm das settings.
     */
    isBot(): boolean;
    /**
     * A linha existe mas o texto foi ocultado deste leitor. O stream desenha um
     * tombstone para não haver reflow silencioso.
     */
    isRedacted(): boolean;
    /** Por nome ou via @here / @all. */
    mentionsActor(): boolean;
    /** Mesmo autor, perto no tempo e nenhuma das duas é de sistema. */
    isGroupedWith(previous: Message | null | undefined, withinSeconds?: number): boolean;
    totalReactions(): number;
    apiEndpoint(): string;
}
