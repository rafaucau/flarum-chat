import Modal from 'flarum/common/components/Modal';
import type { IInternalModalAttrs } from 'flarum/common/components/Modal';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
import { type SendKey } from '../utils/shortcuts';
export interface ChannelInfoModalAttrs extends IInternalModalAttrs {
    channel: Channel;
}
/**
 * Detalhes do canal: notificações, membros e ações de moderação.
 *
 * O ChannelFormModal edita o canal em si e exige permissão de edição; este é o
 * que qualquer membro vê, com cada ação liberada pela flag de permissão dela.
 */
export default class ChannelInfoModal extends Modal<ChannelInfoModalAttrs> {
    private tab;
    /** Membro com os ranks abertos para edição na aba de membros, ou null. */
    private assigningUserId;
    private members;
    /**
     * Moderadores do canal, da última leitura da lista ou promoção. Guardado aqui:
     * as outras leituras do canal vêm sem membros e devolvem isto vazio.
     */
    private moderatorIds;
    /** Convites sem resposta. Só quem gerencia recebe. */
    private invited;
    /**
     * Transferência de dono pendente, como o servidor a mostra a este leitor: a do
     * dono ou a oferecida a ele. Lida junto com os membros, como `moderatorIds`.
     */
    private transfer;
    private loadingMembers;
    private loadedMembers;
    private memberFilter;
    private working;
    /** Para de ouvir pushes de membros; definido enquanto o modal está aberto. */
    private stopListening;
    /**
     * Ação imediata em andamento, ou null. `working` desabilita todos os controles;
     * isto decide qual botão mostra o spinner.
     */
    private pending;
    className(): string;
    title(): Mithril.Children;
    content(): Mithril.Children;
    protected tabButton(tab: 'settings' | 'members' | 'ranks', key: string): Mithril.Children;
    protected settings(): Mithril.Children;
    /**
     * Comportamento do composer do membro. A tecla de envio vale para todos os
     * canais, não só este; o texto de ajuda deixa isso claro.
     */
    protected composerSection(): Mithril.Children;
    /** Descrição do nível de notificação selecionado. */
    protected notificationLevelHelp(channel: Channel): string;
    /**
     * Ações que mudam o canal para todos; cada uma só aparece se o servidor permitir.
     */
    protected moderation(): Mithril.Children;
    protected memberTab(): Mithril.Children;
    /**
     * Convites pendentes, para quem gerencia. Ficam abaixo dos membros porque
     * convite não é participação; cada um pode ser retirado.
     */
    protected invitedList(): Mithril.Children;
    /**
     * Retira um convite, com confirmação: a notificação do convidado some junto.
     */
    protected cancelInvite(user: User): Promise<void>;
    protected isOwner(user: User): boolean;
    protected isModerator(user: User): boolean;
    /** Rank exibido do membro, do `rankBook` do canal. */
    protected rankOf(user: User): import("../../common/models/Channel").RankEntry | null;
    /**
     * Rank do membro como nas linhas de autor: tag, ou nada se o rank só colore o
     * nome. Sem `rankBook` (conversa direta), usa os rótulos de papel.
     */
    protected memberBadge(user: User): Mithril.Children;
    /**
     * Abre os ranks do membro para quem gerencia, inclusive os próprios: um
     * moderador do chat pode ter rank no canal de outra pessoa.
     */
    protected rankControl(user: User): Mithril.Children;
    /**
     * Checklist de ranks sob a linha do membro. Cada marcação grava o conjunto
     * inteiro; o servidor devolve o `rankBook`, que redesenha tudo junto.
     */
    protected rankPicker(user: User): Mithril.Children;
    protected assignRanks(user: User, rankIds: number[]): Promise<void>;
    /**
     * Promover, rebaixar e remover, só para quem o usuário pode afetar. Nada para si
     * mesmo: para isso existe "Sair do canal".
     */
    protected memberControls(user: User): Mithril.Children;
    /**
     * Transferência pendente: o dono digita o código ou cancela; quem recebe aceita
     * ou recusa, como na notificação.
     */
    protected transferBanner(): Mithril.Children;
    /** Nome do membro pelo id, da lista ou do store. */
    protected userName(id: number): string;
    /**
     * Primeiro passo: após confirmar, o servidor manda o código por e-mail ao dono e
     * o diálogo do código abre por cima.
     */
    protected startTransfer(user: User): Promise<void>;
    protected openCodeModal(recipientName: string): void;
    protected cancelTransfer(): Promise<void>;
    protected answerTransfer(accept: boolean): Promise<void>;
    /**
     * Redesenha a aba a partir do canal devolvido. Os endpoints de transferência
     * incluem participantes, então tudo vem dessa resposta.
     */
    protected adoptMembers(channel: Channel | null): void;
    protected reloadMembers(): void;
    /**
     * Dá ou tira o papel de moderador. A resposta traz o canal com participantes,
     * o que atualiza `moderatorIds`.
     */
    protected setModerator(user: User, moderator: boolean): Promise<void>;
    /**
     * Abre o seletor de pessoas empilhado sobre este modal. É a única forma de
     * entrar num canal privado, que não aparece na busca nem aceita entrada direta.
     */
    protected openAddMembers(): void;
    /**
     * Adiciona o resultado do seletor aos convites pendentes. Ninguém vira membro
     * antes de aceitar, então a contagem não muda.
     */
    protected onMembersInvited(users: User[]): void;
    /**
     * Remove alguém do canal, com confirmação: de canal privado a pessoa não volta
     * sozinha.
     */
    protected remove(user: User): Promise<void>;
    protected loadMembers(): Promise<void>;
    onremove(vnode: Mithril.VnodeDOM<ChannelInfoModalAttrs, this>): void;
    protected saveNotifications(level: number | null, muted: boolean | null): Promise<void>;
    /**
     * Grava a tecla de envio no usuário, de forma otimista: o composer lê a
     * preferência a cada tecla.
     *
     * O rollback manual é necessário: `savePreferences` altera o objeto antes do
     * `Model.save`, então o snapshot do core já contém o valor novo.
     */
    protected saveSendKey(value: SendKey): Promise<void>;
    protected setStatus(status: 'open' | 'closed'): Promise<void>;
    protected archive(): Promise<void>;
    protected unarchive(): Promise<void>;
    protected destroy(): Promise<void>;
    /**
     * Executa uma mudança de estado imediata. `action` diz qual botão mostra o
     * spinner; `working` bloqueia os demais controles.
     */
    protected act(action: 'status' | 'archive' | 'unarchive', path: string, attributes: Record<string, unknown>): Promise<void>;
}
