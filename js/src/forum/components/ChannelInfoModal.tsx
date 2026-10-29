import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import type { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Avatar from 'flarum/common/components/Avatar';
import Switch from 'flarum/common/components/Switch';
import username from 'flarum/common/helpers/username';
import classList from 'flarum/common/utils/classList';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';

import userLink from '../utils/userLink';

import type Channel from '../../common/models/Channel';
import { NotificationLevel } from '../../common/models/Channel';
import type { OwnershipTransferState } from '../../common/models/Channel';
import chatState from '../state/chat';
import { isOnline } from '../utils/presence';
import { MembersSkeleton } from './Skeletons';
import { channelIcon } from '../utils/channelIcon';
import { sendKeyPreference, type SendKey } from '../utils/shortcuts';
import iconLabel from '../utils/iconLabel';
import { loadAddMembersModal } from '../utils/lazy';
import TransferCodeModal from './TransferCodeModal';
import { acceptTransfer, cancelTransfer, declineTransfer, startTransfer } from '../utils/transfers';
import { invitationErrorText } from '../utils/invitations';
import ChannelRanksTab, { rankRequest } from './ChannelRanksTab';
import { displayedRank, heldRankIds, rankName, rankNameAttrs, rankTag } from '../utils/ranks';

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
  private tab: 'settings' | 'members' | 'ranks' = 'settings';

  /** Membro com os ranks abertos para edição na aba de membros, ou null. */
  private assigningUserId: string | null = null;
  private members: User[] = [];

  /**
   * Moderadores do canal, da última leitura da lista ou promoção. Guardado aqui:
   * as outras leituras do canal vêm sem membros e devolvem isto vazio.
   */
  private moderatorIds: number[] = [];
  /** Convites sem resposta. Só quem gerencia recebe. */
  private invited: User[] = [];

  /**
   * Transferência de dono pendente, como o servidor a mostra a este leitor: a do
   * dono ou a oferecida a ele. Lida junto com os membros, como `moderatorIds`.
   */
  private transfer: OwnershipTransferState | null = null;
  private loadingMembers = false;
  private loadedMembers = false;
  private memberFilter = '';
  private working = false;

  /** Para de ouvir pushes de membros; definido enquanto o modal está aberto. */
  private stopListening: (() => void) | null = null;

  /**
   * Ação imediata em andamento, ou null. `working` desabilita todos os controles;
   * isto decide qual botão mostra o spinner.
   */
  private pending: 'status' | 'archive' | 'unarchive' | 'delete' | null = null;

  className(): string {
    return 'ChatModal ChatChannelInfoModal Modal--medium';
  }

  title(): Mithril.Children {
    const channel = this.attrs.channel;

    return (
      <>
        {channelIcon(channel, 'ChatChannelInfo-icon')}
        {channel.displayName()}
      </>
    );
  }

  content(): Mithril.Children {
    return (
      <div className="ChatChannelInfo">
        <div className="ChatChannelInfo-tabs">
          {this.tabButton('settings', 'ramon-chat.forum.info.tab_settings')}
          {this.tabButton('members', 'ramon-chat.forum.info.tab_members')}
          {this.attrs.channel.canManageRanks() ? this.tabButton('ranks', 'ramon-chat.forum.ranks.tab') : null}
        </div>

        {this.tab === 'settings'
          ? this.settings()
          : this.tab === 'ranks'
            ? ChannelRanksTab.component({ channel: this.attrs.channel })
            : this.memberTab()}
      </div>
    );
  }

  protected tabButton(tab: 'settings' | 'members' | 'ranks', key: string): Mithril.Children {
    return (
      <button
        type="button"
        className={classList('ChatChannelInfo-tab', {
          'ChatChannelInfo-tab--active': this.tab === tab,
        })}
        onclick={() => {
          this.tab = tab;

          if (tab === 'members') this.loadMembers();
        }}
      >
        {app.translator.trans(key)}
      </button>
    );
  }

  // ── Aba de configurações ──

  protected settings(): Mithril.Children {
    const channel = this.attrs.channel;

    return (
      <div>
        {channel.description() ? (
          <div className="ChatChannelInfo-section">
            <div className="ChatChannelInfo-section-label">{app.translator.trans('ramon-chat.forum.info.description')}</div>
            <div>{channel.description()}</div>
          </div>
        ) : null}

        <div className="ChatChannelInfo-section">
          <div className="ChatChannelInfo-section-label">{app.translator.trans('ramon-chat.forum.info.notifications')}</div>

          <label>
            {app.translator.trans('ramon-chat.forum.info.notification_level')}
            <select
              className="FormControl"
              value={String(channel.notificationLevel() ?? NotificationLevel.Mentions)}
              onchange={(e: Event) => this.saveNotifications(Number((e.target as HTMLSelectElement).value), null)}
            >
              <option value={String(NotificationLevel.Always)}>{app.translator.trans('ramon-chat.forum.info.level_always', {}, true)}</option>
              <option value={String(NotificationLevel.Mentions)}>{app.translator.trans('ramon-chat.forum.info.level_mentions', {}, true)}</option>
              <option value={String(NotificationLevel.Never)}>{app.translator.trans('ramon-chat.forum.info.level_never', {}, true)}</option>
            </select>
          </label>

          {/* O que o nível escolhido faz. */}
          <div className="helpText">{app.translator.trans(this.notificationLevelHelp(channel))}</div>

          <Switch state={Boolean(channel.isMuted())} onchange={(value: boolean) => this.saveNotifications(null, value)} disabled={this.working}>
            {app.translator.trans('ramon-chat.forum.info.mute')}
          </Switch>

          <div className="helpText">{app.translator.trans('ramon-chat.forum.info.mute_help')}</div>
        </div>

        {this.composerSection()}

        {this.moderation()}
      </div>
    );
  }

  /**
   * Comportamento do composer do membro. A tecla de envio vale para todos os
   * canais, não só este; o texto de ajuda deixa isso claro.
   */
  protected composerSection(): Mithril.Children {
    if (!app.session.user) return null;

    const preference = sendKeyPreference();

    return (
      <div className="ChatChannelInfo-section">
        <div className="ChatChannelInfo-section-label">{app.translator.trans('ramon-chat.forum.info.composer')}</div>

        <label>
          {app.translator.trans('ramon-chat.forum.info.send_key')}
          <select
            className="FormControl"
            value={preference}
            disabled={this.working}
            onchange={(e: Event) => this.saveSendKey((e.target as HTMLSelectElement).value as SendKey)}
          >
            <option value="enter">{app.translator.trans('ramon-chat.forum.info.send_key_enter', {}, true)}</option>
            <option value="ctrl">{app.translator.trans('ramon-chat.forum.info.send_key_ctrl', {}, true)}</option>
          </select>
        </label>

        <div className="helpText">
          {app.translator.trans(preference === 'ctrl' ? 'ramon-chat.forum.info.send_key_help_ctrl' : 'ramon-chat.forum.info.send_key_help_enter')}
        </div>
      </div>
    );
  }

  /** Descrição do nível de notificação selecionado. */
  protected notificationLevelHelp(channel: Channel): string {
    switch (channel.notificationLevel() ?? NotificationLevel.Mentions) {
      case NotificationLevel.Always:
        return 'ramon-chat.forum.info.level_always_help';
      case NotificationLevel.Never:
        return 'ramon-chat.forum.info.level_never_help';
      default:
        return 'ramon-chat.forum.info.level_mentions_help';
    }
  }

  /**
   * Ações que mudam o canal para todos; cada uma só aparece se o servidor permitir.
   */
  protected moderation(): Mithril.Children {
    const channel = this.attrs.channel;
    const items: Mithril.Children[] = [];

    if (channel.canClose() && !channel.isArchived()) {
      const closed = channel.status() === 'closed';

      items.push(
        <Button
          className="Button"
          icon={closed ? 'fas fa-lock-open' : 'fas fa-lock'}
          loading={this.pending === 'status'}
          disabled={this.working}
          onclick={() => this.setStatus(closed ? 'open' : 'closed')}
        >
          {app.translator.trans(closed ? 'ramon-chat.forum.info.reopen_channel' : 'ramon-chat.forum.info.close_channel')}
        </Button>
      );
    }

    if (channel.canArchive() && !channel.isArchived()) {
      items.push(
        <Button
          className="Button"
          icon="fas fa-box-archive"
          loading={this.pending === 'archive'}
          disabled={this.working}
          onclick={() => this.archive()}
        >
          {app.translator.trans('ramon-chat.forum.info.archive_channel')}
        </Button>
      );
    }

    // Lido do status, não de `archivedAt`, que pode sobrar após reabrir o canal.
    if (channel.isArchived() && channel.canUnarchive()) {
      items.push(
        <Button
          className="Button"
          icon="fas fa-box-open"
          loading={this.pending === 'unarchive'}
          disabled={this.working}
          onclick={() => this.unarchive()}
        >
          {app.translator.trans('ramon-chat.forum.info.unarchive_channel')}
        </Button>
      );
    }

    if (items.length === 0 && !channel.canDelete()) return null;

    return (
      <>
        {items.length > 0 ? <div className="ChatChannelInfo-section">{items}</div> : null}

        {channel.canDelete() ? (
          <div className="ChatChannelInfo-danger">
            <Button
              className="Button ChatChannelInfo-deleteButton"
              icon="fas fa-trash"
              loading={this.pending === 'delete'}
              disabled={this.working}
              onclick={() => this.destroy()}
            >
              {app.translator.trans('ramon-chat.forum.info.delete_channel')}
            </Button>
          </div>
        ) : null}
      </>
    );
  }

  // ── Aba de membros ──

  protected memberTab(): Mithril.Children {
    if (this.loadingMembers) {
      return <div className="ChatChannelInfo-section">{MembersSkeleton()}</div>;
    }

    const term = this.memberFilter.trim().toLowerCase();
    const shown = term ? this.members.filter((user) => (user.displayName() + ' ' + user.username()).toLowerCase().includes(term)) : this.members;

    // Em canal para todos a lista mostra só quem administra, e não há quem adicionar.
    const forEveryone = this.attrs.channel.isForEveryone();

    return (
      <div className="ChatChannelInfo-section">
        <div className="ChatChannelInfo-memberHeader">
          <span className="ChatChannelInfo-memberCount">
            {forEveryone
              ? app.translator.trans('ramon-chat.forum.channel.everyone')
              : app.translator.trans('ramon-chat.forum.channel.members', {
                  count: this.members.length,
                })}
          </span>

          {/* Abre um seletor à parte: um campo de candidatos sobre o de busca de
              membros pareceria o mesmo controle. */}
          {this.attrs.channel.canManageMembers() && !forEveryone ? (
            <Button
              className="Button Button--primary Button--compact"
              icon="fas fa-user-plus"
              disabled={this.working}
              onclick={() => this.openAddMembers()}
            >
              {/* Título do diálogo; `info.add_member` é texto de campo de busca. */}
              {app.translator.trans('ramon-chat.forum.add_members.title')}
            </Button>
          ) : null}
        </div>

        {forEveryone ? <p className="helpText ChatChannelInfo-everyoneHelp">{app.translator.trans('ramon-chat.forum.info.everyone_help')}</p> : null}

        {/* Explica o papel para quem pode atribuí-lo. */}
        {this.attrs.channel.canManageModerators() ? (
          <p className="helpText ChatChannelInfo-moderatorHelp">{app.translator.trans('ramon-chat.forum.info.moderator_help')}</p>
        ) : null}

        {this.transferBanner()}

        <input
          className="FormControl ChatChannelInfo-filter"
          type="search"
          placeholder={app.translator.trans('ramon-chat.forum.info.member_search', {}, true)}
          value={this.memberFilter}
          oninput={(e: Event) => {
            this.memberFilter = (e.target as HTMLInputElement).value;
          }}
        />

        <div className="ChatChannelInfo-memberList">
          {/* Um item com key por membro, com os ranks dentro: lista com key não
              pode ter buracos. */}
          {shown.map((user) => (
            <div key={user.id()} className="ChatChannelInfo-memberItem">
              <div
                className={classList('ChatChannelInfo-member', {
                  'ChatChannelInfo-member--online': isOnline(user),
                })}
              >
                <Avatar user={user} className="Avatar" />
                <span {...rankNameAttrs(this.rankOf(user))}>{userLink(user)}</span>
                {this.memberBadge(user)}
                {this.rankControl(user)}
                {this.memberControls(user)}
              </div>

              {this.assigningUserId === user.id() ? this.rankPicker(user) : null}
            </div>
          ))}

          {shown.length === 0 ? <div className="ChatBrowse-empty">{app.translator.trans('ramon-chat.forum.info.no_members')}</div> : null}
        </div>

        {this.invitedList()}
      </div>
    );
  }

  /**
   * Convites pendentes, para quem gerencia. Ficam abaixo dos membros porque
   * convite não é participação; cada um pode ser retirado.
   */
  protected invitedList(): Mithril.Children {
    if (!this.attrs.channel.canManageMembers() || this.invited.length === 0) {
      return null;
    }

    return (
      <div className="ChatChannelInfo-invited">
        <div className="ChatChannelInfo-memberHeader">
          <span className="ChatChannelInfo-memberCount">
            {app.translator.trans('ramon-chat.forum.info.invited_heading', {
              count: this.invited.length,
            })}
          </span>
        </div>

        <div className="ChatChannelInfo-memberList">
          {this.invited.map((user) => (
            <div key={user.id()} className="ChatChannelInfo-member ChatChannelInfo-member--invited">
              <Avatar user={user} className="Avatar" />
              <span>{userLink(user)}</span>
              <span className="ChatChannelInfo-member-badge">{app.translator.trans('ramon-chat.forum.info.invited_badge')}</span>
              <Button
                className="Button Button--icon Button--flat ChatChannelInfo-member-remove"
                icon="fas fa-user-xmark"
                disabled={this.working}
                {...iconLabel(app.translator.trans('ramon-chat.forum.info.cancel_invite', { username: username(user) }, true))}
                onclick={() => this.cancelInvite(user)}
              />
            </div>
          ))}
        </div>
      </div>
    );
  }

  /**
   * Retira um convite, com confirmação: a notificação do convidado some junto.
   */
  protected async cancelInvite(user: User): Promise<void> {
    const confirmed = confirm(app.translator.trans('ramon-chat.forum.info.cancel_invite_confirm', { username: username(user) }, true));

    if (!confirmed) return;

    this.working = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-channels/${this.attrs.channel.id()}/invites/cancel`,
        body: { data: { attributes: { userId: Number(user.id()) } } },
      });

      if (payload?.data) app.store.pushPayload(payload);

      this.invited = this.invited.filter((entry) => entry.id() !== user.id());

      app.alerts.show(
        { type: 'success' },
        app.translator.trans('ramon-chat.forum.info.invite_cancelled', {
          username: username(user),
        })
      );
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.info.save_failed'));
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected isOwner(user: User): boolean {
    const creatorId = this.attrs.channel.creatorId();

    return creatorId != null && Number(creatorId) === Number(user.id());
  }

  protected isModerator(user: User): boolean {
    return this.moderatorIds.some((id) => Number(id) === Number(user.id()));
  }

  /** Rank exibido do membro, do `rankBook` do canal. */
  protected rankOf(user: User) {
    const book = this.attrs.channel.rankBook?.();

    return book && Array.isArray(book.ranks) ? displayedRank(book, Number(user.id())) : null;
  }

  /**
   * Rank do membro como nas linhas de autor: tag, ou nada se o rank só colore o
   * nome. Sem `rankBook` (conversa direta), usa os rótulos de papel.
   */
  protected memberBadge(user: User): Mithril.Children {
    const book = this.attrs.channel.rankBook?.();

    if (book && Array.isArray(book.ranks)) {
      const rank = displayedRank(book, Number(user.id()));

      return rank?.showBadge ? rankTag(rank, 'ChatChannelInfo-memberRank') : null;
    }

    if (this.isOwner(user)) {
      return (
        <span className="ChatChannelInfo-member-badge ChatChannelInfo-member-badge--owner">
          {app.translator.trans('ramon-chat.forum.info.owner_badge')}
        </span>
      );
    }

    if (this.isModerator(user)) {
      return (
        <span className="ChatChannelInfo-member-badge ChatChannelInfo-member-badge--moderator">
          {app.translator.trans('ramon-chat.forum.info.moderator_badge')}
        </span>
      );
    }

    return null;
  }

  /**
   * Abre os ranks do membro para quem gerencia, inclusive os próprios: um
   * moderador do chat pode ter rank no canal de outra pessoa.
   */
  protected rankControl(user: User): Mithril.Children {
    if (!this.attrs.channel.canManageRanks()) return null;

    const open = this.assigningUserId === user.id();

    return (
      <Button
        className={classList('Button Button--icon Button--flat ChatChannelInfo-member-ranks', { active: open })}
        icon="fas fa-tags"
        disabled={this.working}
        aria-expanded={open ? 'true' : 'false'}
        {...iconLabel(app.translator.trans('ramon-chat.forum.ranks.assign', { username: username(user) }, true))}
        onclick={() => {
          this.assigningUserId = open ? null : (user.id() as string);
        }}
      />
    );
  }

  /**
   * Checklist de ranks sob a linha do membro. Cada marcação grava o conjunto
   * inteiro; o servidor devolve o `rankBook`, que redesenha tudo junto.
   */
  protected rankPicker(user: User): Mithril.Children {
    const book = this.attrs.channel.rankBook?.();
    const custom = book && Array.isArray(book.ranks) ? book.ranks.filter((rank) => rank.builtin === null) : [];
    const held = book ? heldRankIds(book, Number(user.id())) : [];

    return (
      <div
        className="ChatChannelInfo-rankPicker"
        role="group"
        aria-label={app.translator.trans('ramon-chat.forum.ranks.assign', { username: username(user) }, true)}
      >
        {custom.length === 0 ? (
          <span className="helpText">{app.translator.trans('ramon-chat.forum.ranks.assign_empty')}</span>
        ) : (
          custom.map((rank) => {
            const checked = held.includes(Number(rank.id));

            return (
              <label
                key={rank.key}
                className={classList('ChatChannelInfo-rankOption', {
                  'ChatChannelInfo-rankOption--checked': checked,
                })}
              >
                <input
                  type="checkbox"
                  checked={checked}
                  disabled={this.working}
                  onchange={() => this.assignRanks(user, checked ? held.filter((id) => id !== Number(rank.id)) : [...held, Number(rank.id)])}
                />
                {rank.showBadge ? rankTag(rank) : <span {...rankNameAttrs(rank)}>{rankName(rank)}</span>}
              </label>
            );
          })
        )}
      </div>
    );
  }

  protected async assignRanks(user: User, rankIds: number[]): Promise<void> {
    if (this.working) return;

    this.working = true;
    m.redraw();

    try {
      await rankRequest(this.attrs.channel.id() as string, '/assign', {
        userId: Number(user.id()),
        rankIds,
      });
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, invitationErrorText(e, 'ramon-chat.forum.ranks.save_failed'));
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  /**
   * Promover, rebaixar e remover, só para quem o usuário pode afetar. Nada para si
   * mesmo: para isso existe "Sair do canal".
   */
  protected memberControls(user: User): Mithril.Children {
    const channel = this.attrs.channel;

    if (user.id() === app.session.user?.id()) return null;

    const controls: Mithril.Children[] = [];

    if (channel.canManageModerators() && !this.isOwner(user)) {
      const moderator = this.isModerator(user);

      controls.push(
        <Button
          className="Button Button--icon Button--flat ChatChannelInfo-member-role"
          icon={moderator ? 'fas fa-user-slash' : 'fas fa-user-shield'}
          disabled={this.working}
          {...iconLabel(
            app.translator.trans(
              moderator ? 'ramon-chat.forum.info.demote_moderator' : 'ramon-chat.forum.info.promote_moderator',
              { username: username(user) },
              true
            )
          )}
          onclick={() => this.setModerator(user, !moderator)}
        />
      );
    }

    // Transferir o canal: para o dono e admins. O servidor decide se o membro pode
    // ser dono.
    if (channel.canTransferOwnership() && !this.isOwner(user)) {
      controls.push(
        <Button
          className="Button Button--icon Button--flat ChatChannelInfo-member-transfer"
          icon="fas fa-crown"
          disabled={this.working}
          {...iconLabel(app.translator.trans('ramon-chat.forum.info.transfer_ownership', { username: username(user) }, true))}
          onclick={() => this.startTransfer(user)}
        />
      );
    }

    if (channel.canManageMembers()) {
      controls.push(
        <Button
          className="Button Button--icon Button--flat ChatChannelInfo-member-remove"
          icon="fas fa-user-minus"
          disabled={this.working}
          {...iconLabel(app.translator.trans('ramon-chat.forum.info.remove_member', { username: username(user) }, true))}
          onclick={() => this.remove(user)}
        />
      );
    }

    return controls;
  }

  /**
   * Transferência pendente: o dono digita o código ou cancela; quem recebe aceita
   * ou recusa, como na notificação.
   */
  protected transferBanner(): Mithril.Children {
    const transfer = this.transfer;

    if (!transfer) return null;

    let text: Mithril.Children;
    const actions: Mithril.Children[] = [];

    if (transfer.incoming) {
      text = app.translator.trans('ramon-chat.forum.info.transfer_incoming', {
        username: this.userName(transfer.fromUserId),
      });

      actions.push(
        <Button
          className="Button Button--primary Button--compact"
          icon="fas fa-check"
          disabled={this.working}
          onclick={() => this.answerTransfer(true)}
        >
          {app.translator.trans('ramon-chat.forum.notifications.invite_accept')}
        </Button>,
        <Button className="Button Button--compact" icon="fas fa-xmark" disabled={this.working} onclick={() => this.answerTransfer(false)}>
          {app.translator.trans('ramon-chat.forum.notifications.invite_decline')}
        </Button>
      );
    } else {
      const recipient = this.userName(transfer.toUserId);

      text = app.translator.trans(
        transfer.confirmed ? 'ramon-chat.forum.info.transfer_pending_answer' : 'ramon-chat.forum.info.transfer_pending_code',
        { username: recipient }
      );

      if (!transfer.confirmed && String(transfer.fromUserId) === String(app.session.user?.id())) {
        actions.push(
          <Button
            className="Button Button--primary Button--compact"
            icon="fas fa-key"
            disabled={this.working}
            onclick={() => this.openCodeModal(recipient)}
          >
            {app.translator.trans('ramon-chat.forum.info.transfer_enter_code')}
          </Button>
        );
      }

      actions.push(
        <Button className="Button Button--compact" icon="fas fa-xmark" disabled={this.working} onclick={() => this.cancelTransfer()}>
          {app.translator.trans('ramon-chat.forum.info.transfer_cancel')}
        </Button>
      );
    }

    return (
      <div className="ChatChannelInfo-transfer">
        <i className="fas fa-crown" aria-hidden="true" />
        <span className="ChatChannelInfo-transfer-text">{text}</span>
        <span className="ChatChannelInfo-transfer-actions">{actions}</span>
      </div>
    );
  }

  /** Nome do membro pelo id, da lista ou do store. */
  protected userName(id: number): string {
    const user = this.members.find((member) => Number(member.id()) === Number(id)) ?? (app.store.getById('users', String(id)) as User | undefined);

    return user ? user.displayName() : (app.translator.trans('ramon-chat.forum.notifications.someone', {}, true) as string);
  }

  /**
   * Primeiro passo: após confirmar, o servidor manda o código por e-mail ao dono e
   * o diálogo do código abre por cima.
   */
  protected async startTransfer(user: User): Promise<void> {
    if (this.working) return;

    const confirmed = confirm(
      app.translator.trans(
        'ramon-chat.forum.info.transfer_confirm_start',
        {
          channel: this.attrs.channel.displayName(),
          username: username(user),
        },
        true
      )
    );

    if (!confirmed) return;

    this.working = true;
    m.redraw();

    try {
      const channel = await startTransfer(this.attrs.channel.id() as string, Number(user.id()));

      this.adoptMembers(channel);
      this.openCodeModal(user.displayName());
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, invitationErrorText(e, 'ramon-chat.forum.info.transfer_failed'));
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected openCodeModal(recipientName: string): void {
    app.modal.show(
      TransferCodeModal,
      {
        channel: this.attrs.channel,
        recipientName,
        onConfirmed: (channel: Channel | null) => {
          if (channel) {
            this.adoptMembers(channel);
          } else {
            this.reloadMembers();
          }
        },
      },
      true
    );
  }

  protected async cancelTransfer(): Promise<void> {
    if (this.working) return;

    this.working = true;
    m.redraw();

    try {
      this.adoptMembers(await cancelTransfer(this.attrs.channel.id() as string));

      app.alerts.show({ type: 'success' }, app.translator.trans('ramon-chat.forum.info.transfer_cancelled'));
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, invitationErrorText(e, 'ramon-chat.forum.info.transfer_failed'));
      this.reloadMembers();
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected async answerTransfer(accept: boolean): Promise<void> {
    if (this.working) return;

    this.working = true;
    m.redraw();

    try {
      const id = this.attrs.channel.id() as string;

      this.adoptMembers(await (accept ? acceptTransfer(id) : declineTransfer(id)));

      app.alerts.show(
        { type: 'success' },
        app.translator.trans(accept ? 'ramon-chat.forum.info.transfer_accepted' : 'ramon-chat.forum.info.transfer_declined')
      );
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, invitationErrorText(e, 'ramon-chat.forum.info.transfer_failed'));
      this.reloadMembers();
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  /**
   * Redesenha a aba a partir do canal devolvido. Os endpoints de transferência
   * incluem participantes, então tudo vem dessa resposta.
   */
  protected adoptMembers(channel: Channel | null): void {
    if (!channel) {
      this.reloadMembers();

      return;
    }

    this.members = (channel.participants() || []).filter(Boolean) as User[];
    this.moderatorIds = (channel.moderatorIds() ?? []).map(Number);
    this.transfer = channel.ownershipTransfer() ?? null;
  }

  protected reloadMembers(): void {
    this.loadedMembers = false;
    void this.loadMembers();
  }

  /**
   * Dá ou tira o papel de moderador. A resposta traz o canal com participantes,
   * o que atualiza `moderatorIds`.
   */
  protected async setModerator(user: User, moderator: boolean): Promise<void> {
    if (this.working) return;

    this.working = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-channels/${this.attrs.channel.id()}/moderators${moderator ? '' : '/remove'}`,
        body: { data: { attributes: { userId: Number(user.id()) } } },
      });

      if (payload?.data) app.store.pushPayload(payload);

      const ids = payload?.data?.attributes?.moderatorIds;

      if (Array.isArray(ids)) this.moderatorIds = ids.map(Number);

      app.alerts.show(
        { type: 'success' },
        app.translator.trans(moderator ? 'ramon-chat.forum.info.moderator_promoted' : 'ramon-chat.forum.info.moderator_demoted', {
          username: username(user),
        })
      );
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.info.save_failed'));
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  /**
   * Abre o seletor de pessoas empilhado sobre este modal. É a única forma de
   * entrar num canal privado, que não aparece na busca nem aceita entrada direta.
   */
  protected openAddMembers(): void {
    app.modal.show(
      loadAddMembersModal,
      {
        channel: this.attrs.channel,
        // O seletor mostra membros e convidados marcados, não selecionáveis.
        members: this.members,
        invited: this.invited,
        onAdded: (users: User[]) => this.onMembersInvited(users),
      },
      true
    );
  }

  /**
   * Adiciona o resultado do seletor aos convites pendentes. Ninguém vira membro
   * antes de aceitar, então a contagem não muda.
   */
  protected onMembersInvited(users: User[]): void {
    const known = new Set([...this.members.map((member) => member.id()), ...this.invited.map((user) => user.id())]);
    const fresh = users.filter((user) => !known.has(user.id()));

    if (fresh.length === 0) return;

    this.invited = [...this.invited, ...fresh];

    m.redraw();
  }

  /**
   * Remove alguém do canal, com confirmação: de canal privado a pessoa não volta
   * sozinha.
   */
  protected async remove(user: User): Promise<void> {
    const confirmed = confirm(app.translator.trans('ramon-chat.forum.info.remove_member_confirm', { username: username(user) }, true));

    if (!confirmed) return;

    this.working = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-channels/${this.attrs.channel.id()}/members/remove`,
        body: { data: { attributes: { userId: Number(user.id()) } } },
      });

      if (payload?.data) app.store.pushPayload(payload);

      this.members = this.members.filter((member) => member.id() !== user.id());

      // Acompanha o decremento do servidor até o próximo fetch.
      this.attrs.channel.pushAttributes({
        userCount: Math.max(0, (this.attrs.channel.userCount() ?? 1) - 1),
      });

      app.alerts.show(
        { type: 'success' },
        app.translator.trans('ramon-chat.forum.info.member_removed', {
          username: username(user),
        })
      );
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.info.save_failed'));
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected async loadMembers(): Promise<void> {
    if (this.loadedMembers || this.loadingMembers) return;

    this.loadingMembers = true;

    // Daqui em diante, um push de membros relê a lista com a aba aberta.
    this.stopListening ??= chatState.onMembershipChange((channelId) => {
      if (channelId !== Number(this.attrs.channel.id())) return;

      this.loadedMembers = false;
      void this.loadMembers();
    });

    try {
      // Busca com relações: a cópia da sidebar vem sem participantes. Os convites
      // pendentes só vêm para quem gerencia.
      const channel = (await app.store.find('chat-channels', String(this.attrs.channel.id()), {
        include: 'participants,invitedUsers',
      })) as unknown as Channel;

      this.members = (channel.participants() || []).filter(Boolean) as User[];
      this.moderatorIds = (channel.moderatorIds() ?? []).map(Number);
      this.invited = (channel.invitedUsers() || []).filter(Boolean) as User[];
      this.transfer = channel.ownershipTransfer() ?? null;
    } catch {
      this.members = [];
      this.invited = [];
      this.transfer = null;
    } finally {
      this.loadingMembers = false;
      this.loadedMembers = true;
      m.redraw();
    }
  }

  onremove(vnode: Mithril.VnodeDOM<ChannelInfoModalAttrs, this>): void {
    super.onremove(vnode);

    this.stopListening?.();
    this.stopListening = null;
  }

  // ── Ações ──

  protected async saveNotifications(level: number | null, muted: boolean | null): Promise<void> {
    const channel = this.attrs.channel;

    // Otimista: o endpoint responde 204, sem nada a aplicar.
    channel.pushAttributes({
      ...(level !== null ? { notificationLevel: level } : {}),
      ...(muted !== null ? { isMuted: muted } : {}),
    });

    this.working = true;
    m.redraw();

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-channels/${channel.id()}/notifications`,
        body: {
          data: {
            attributes: {
              ...(level !== null ? { notificationLevel: level } : {}),
              ...(muted !== null ? { muted } : {}),
            },
          },
        },
      });
    } catch {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.info.save_failed'));
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  /**
   * Grava a tecla de envio no usuário, de forma otimista: o composer lê a
   * preferência a cada tecla.
   *
   * O rollback manual é necessário: `savePreferences` altera o objeto antes do
   * `Model.save`, então o snapshot do core já contém o valor novo.
   */
  protected async saveSendKey(value: SendKey): Promise<void> {
    const user = app.session.user;

    if (!user) return;

    const previous = sendKeyPreference();

    if (value === previous) return;

    this.working = true;

    try {
      await user.savePreferences({ 'ramon-chat.sendWithCtrlEnter': value });
    } catch {
      await user.savePreferences({ 'ramon-chat.sendWithCtrlEnter': previous }).catch(() => {});

      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.info.save_failed'));
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected async setStatus(status: 'open' | 'closed'): Promise<void> {
    await this.act('status', `/chat-channels/${this.attrs.channel.id()}/status`, {
      status,
    });
  }

  protected async archive(): Promise<void> {
    await this.act('archive', `/chat-channels/${this.attrs.channel.id()}/archive`, {});
  }

  protected async unarchive(): Promise<void> {
    await this.act('unarchive', `/chat-channels/${this.attrs.channel.id()}/unarchive`, {});
  }

  protected async destroy(): Promise<void> {
    if (!confirm(app.translator.trans('ramon-chat.forum.info.delete_confirm', {}, true))) return;

    this.pending = 'delete';
    this.working = true;

    try {
      await this.attrs.channel.delete();

      chatState.channels = chatState.channels.filter((c) => c.id() !== this.attrs.channel.id());

      if (chatState.activeChannelId === Number(this.attrs.channel.id())) {
        chatState.setActiveChannel(null);
      }

      this.hide();
    } catch {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.info.save_failed'));
    } finally {
      this.pending = null;
      this.working = false;
      m.redraw();
    }
  }

  /**
   * Executa uma mudança de estado imediata. `action` diz qual botão mostra o
   * spinner; `working` bloqueia os demais controles.
   */
  protected async act(action: 'status' | 'archive' | 'unarchive', path: string, attributes: Record<string, unknown>): Promise<void> {
    this.pending = action;
    this.working = true;
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}${path}`,
        body: { data: { attributes } },
      });

      if (payload?.data) app.store.pushPayload(payload);

      this.hide();
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.info.save_failed'));
    } finally {
      this.pending = null;
      this.working = false;
      m.redraw();
    }
  }
}
