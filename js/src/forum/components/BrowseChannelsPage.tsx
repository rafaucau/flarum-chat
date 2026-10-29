import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import type { IPageAttrs } from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import chatState from '../state/chat';
import { BrowseSkeleton } from './Skeletons';
import { channelIcon } from '../utils/channelIcon';
import { mobileTitleControl } from '../utils/toolbar';
import { acceptInvitation, adoptChannelPayload, declineInvitation, invitationErrorText } from '../utils/invitations';
import { loadChannelFormModal } from '../utils/lazy';

type BrowseFilter = 'all' | 'open' | 'closed' | 'archived' | 'mine';

/**
 * Página "Explorar canais": todos os canais visíveis, com botões de entrar e, para
 * quem pode, de criar. É o único lugar onde se cria canal, por isso a sidebar
 * aponta para cá mesmo sem canais.
 */
export default class BrowseChannelsPage<CustomAttrs extends IPageAttrs = IPageAttrs> extends Page<CustomAttrs> {
  private channels: Channel[] = [];
  private loading = true;
  private filter: BrowseFilter = 'all';
  private query = '';
  private searchTimer: number | null = null;

  oninit(vnode: Mithril.Vnode<CustomAttrs>): void {
    super.oninit(vnode);

    const title = app.translator.trans('ramon-chat.forum.browse.title', {}, true);

    app.setTitle(title);

    // Dá a seta de voltar na toolbar do celular: o `Navigation` do core só a
    // desenha quando `app.history.canGoBack()` (ver `ChatPage.recordHistory`).
    app.history.push('chat.browse', title);

    const routeFilter = m.route.param('filter') as BrowseFilter | undefined;

    if (routeFilter && ['all', 'open', 'closed', 'archived', 'mine'].includes(routeFilter)) {
      this.filter = routeFilter;
    }

    this.load();
  }

  view(): Mithril.Children {
    return (
      <div className="ChatBrowse">
        {mobileTitleControl(app.translator.trans('ramon-chat.forum.browse.title', {}, true))}

        {/* Acima do título, não ao lado: na mesma linha colidia com os botões em
            telas estreitas. */}
        <button type="button" className="ChatBrowse-back" onclick={() => m.route.set(app.route('chat.index'))}>
          <i className="fas fa-arrow-left" aria-hidden="true" />
          {app.translator.trans('ramon-chat.forum.nav.chat')}
        </button>

        <div className="ChatBrowse-header">
          <div className="ChatBrowse-heading">
            <h1 className="ChatBrowse-title">{app.translator.trans('ramon-chat.forum.browse.title')}</h1>
            {/* O texto sobre criar canal só aparece para quem pode criar, como o botão. */}
            <p className="ChatBrowse-subtitle">
              {app.translator.trans(
                app.forum.attribute<boolean>('canCreateChatChannel')
                  ? 'ramon-chat.forum.browse.subtitle'
                  : 'ramon-chat.forum.browse.subtitle_join_only'
              )}
            </p>
          </div>

          {app.forum.attribute<boolean>('canCreateChatChannel') ? (
            <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => this.create()}>
              {app.translator.trans('ramon-chat.forum.browse.new_channel')}
            </Button>
          ) : null}
        </div>

        <div className="ChatBrowse-toolbar">
          {/* Só um ativo por vez; `aria-pressed` indica qual ao leitor de tela. */}
          <div className="ChatBrowse-filters" role="group" aria-label={app.translator.trans('ramon-chat.forum.browse.title', {}, true)}>
            {(['all', 'open', 'closed', 'archived', 'mine'] as BrowseFilter[]).map((f) => (
              <button
                key={f}
                type="button"
                className={classList('ChatBrowse-filter', {
                  'ChatBrowse-filter--active': this.filter === f,
                })}
                aria-pressed={this.filter === f}
                onclick={() => this.setFilter(f)}
              >
                {app.translator.trans(`ramon-chat.forum.browse.filter_${f}`)}
              </button>
            ))}
          </div>

          <div className="ChatBrowse-search">
            <i className="ChatBrowse-search-icon fas fa-magnifying-glass" aria-hidden="true" />
            <input
              className="ChatBrowse-search-input"
              type="search"
              placeholder={app.translator.trans('ramon-chat.forum.browse.search_placeholder', {}, true)}
              aria-label={app.translator.trans('ramon-chat.forum.browse.search_placeholder', {}, true)}
              value={this.query}
              oninput={(e: Event) => this.onSearch((e.target as HTMLInputElement).value)}
            />

            {/* O botão nativo de limpar do `type="search"` varia por engine e fica
                oculto pelo estilo, então o botão é próprio. */}
            {this.query !== '' ? (
              <button
                type="button"
                className="ChatBrowse-search-clear"
                title={app.translator.trans('ramon-chat.forum.browse.clear_search', {}, true)}
                aria-label={app.translator.trans('ramon-chat.forum.browse.clear_search', {}, true)}
                onclick={() => this.clearSearch()}
              >
                <i className="fas fa-times" aria-hidden="true" />
              </button>
            ) : null}
          </div>
        </div>

        {this.loading ? (
          BrowseSkeleton()
        ) : this.channels.length === 0 ? (
          this.empty()
        ) : (
          <div className="ChatBrowse-list">{this.channels.map((channel) => this.card(channel))}</div>
        )}
      </div>
    );
  }

  /**
   * Estado vazio. Fórum sem canais oferece criar um; busca sem resultado oferece
   * voltar à lista completa.
   */
  protected empty(): Mithril.Children {
    const searching = this.query.trim() !== '';

    return (
      <div className="ChatBrowse-empty">
        <div className="ChatBrowse-empty-icon" aria-hidden="true">
          <i className={searching ? 'fas fa-magnifying-glass' : 'fas fa-comments'} />
        </div>

        <p className="ChatBrowse-empty-text">
          {searching
            ? app.translator.trans('ramon-chat.forum.browse.empty_search', {
                query: this.query.trim(),
              })
            : app.translator.trans('ramon-chat.forum.browse.empty')}
        </p>

        {searching ? (
          <Button className="Button" onclick={() => this.clearSearch()}>
            {app.translator.trans('ramon-chat.forum.browse.clear_search')}
          </Button>
        ) : app.forum.attribute<boolean>('canCreateChatChannel') ? (
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => this.create()}>
            {app.translator.trans('ramon-chat.forum.browse.new_channel')}
          </Button>
        ) : null}
      </div>
    );
  }

  protected card(channel: Channel): Mithril.Children {
    const nameId = `ChatBrowseCard-name-${channel.id()}`;
    const description = channel.description();

    return (
      <article
        className={classList('ChatBrowseCard', {
          'ChatBrowseCard--frozen': !channel.isOpen(),
        })}
        key={channel.id()}
        aria-labelledby={nameId}
      >
        <div className="ChatBrowseCard-head">
          {channelIcon(channel, 'ChatBrowseCard-icon')}

          <div className="ChatBrowseCard-headText">
            <h2 className="ChatBrowseCard-name" id={nameId}>
              <span>{channel.displayName()}</span>
            </h2>

            <div className="ChatBrowseCard-badges">
              {/* Único badge sobre o leitor, não o canal: vem primeiro e tem cor. */}
              {channel.isFollowing() && channel.isHiddenMember() ? (
                <span className="ChatBrowseCard-status ChatBrowseCard-status--joined ChatBrowseCard-status--inspecting">
                  <i className="fas fa-user-secret" aria-hidden="true" />
                  {app.translator.trans('ramon-chat.forum.browse.inspecting')}
                </span>
              ) : channel.isFollowing() ? (
                <span className="ChatBrowseCard-status ChatBrowseCard-status--joined">
                  <i className="fas fa-check" aria-hidden="true" />
                  {app.translator.trans('ramon-chat.forum.browse.joined')}
                </span>
              ) : channel.isInvited() ? (
                <span className="ChatBrowseCard-status ChatBrowseCard-status--invited">
                  <i className="fas fa-envelope-open" aria-hidden="true" />
                  {app.translator.trans('ramon-chat.forum.browse.invited')}
                </span>
              ) : null}

              {channel.isPrivate() ? (
                <span className="ChatBrowseCard-status">
                  <i className="ChatBrowseCard-lock fas fa-lock" aria-hidden="true" />
                  {app.translator.trans('ramon-chat.forum.new_channel.private')}
                </span>
              ) : null}

              {channel.isOpen() ? null : (
                <span className="ChatBrowseCard-status ChatBrowseCard-status--frozen">
                  <i className={channel.isArchived() ? 'fas fa-box-archive' : 'fas fa-lock-open'} aria-hidden="true" />
                  {app.translator.trans(channel.isArchived() ? 'ramon-chat.forum.browse.filter_archived' : 'ramon-chat.forum.browse.filter_closed')}
                </span>
              )}

              {/* Junto de privado/arquivado, não das contagens: a linha de meta fica
                  com dois itens e os botões dos cards ficam alinhados. */}
              {channel.threadingEnabled() ? (
                <span className="ChatBrowseCard-status">
                  <i className="fas fa-code-branch" aria-hidden="true" />
                  {app.translator.trans('ramon-chat.forum.channel.threading_enabled')}
                </span>
              ) : null}
            </div>
          </div>
        </div>

        <p
          className={classList('ChatBrowseCard-description', {
            'ChatBrowseCard-description--empty': !description,
          })}
        >
          {description || app.translator.trans('ramon-chat.forum.browse.no_description')}
        </p>

        <div className="ChatBrowseCard-footer">
          <div className="ChatBrowseCard-meta">
            <span>
              <i className="fas fa-user" aria-hidden="true" />
              {/* Canal para todos só conta quem tem linha nele, o que não diz
                  quantos estão na sala. */}
              {channel.isForEveryone()
                ? app.translator.trans('ramon-chat.forum.channel.everyone')
                : app.translator.trans('ramon-chat.forum.channel.members', {
                    count: channel.userCount() ?? 0,
                  })}
            </span>
            {/* Contagem de mensagens, traduzida. */}
            <span>
              <i className="fas fa-comment" aria-hidden="true" />
              {app.translator.trans('ramon-chat.forum.browse.messages', {
                count: channel.messagesCount() ?? 0,
              })}
            </span>
          </div>

          <div className="ChatBrowseCard-action">
            {channel.canEdit() && channel.isCategory()
              ? this.iconButton('fas fa-pen-to-square', 'ramon-chat.forum.channel.edit', () => this.edit(channel))
              : null}

            {channel.isFollowing() ? (
              <Button className="Button" icon="fas fa-arrow-right" onclick={() => this.open(channel)}>
                {app.translator.trans('ramon-chat.forum.browse.open')}
              </Button>
            ) : channel.isInvited() ? (
              <>
                {this.iconButton('fas fa-xmark', 'ramon-chat.forum.channel.decline_invite', () => this.answerInvitation(channel, false), true)}
                <Button className="Button Button--primary" icon="fas fa-check" onclick={() => this.answerInvitation(channel, true)}>
                  {app.translator.trans('ramon-chat.forum.channel.accept_invite')}
                </Button>
              </>
            ) : (
              <>
                {/* Inspecionar: entrar sem aparecer na lista, para quem tem a permissão. */}
                {channel.canJoinHidden()
                  ? this.iconButton('fas fa-user-secret', 'ramon-chat.forum.channel.join_hidden', () => this.join(channel, true))
                  : null}

                {channel.canJoin() ? (
                  <Button className="Button Button--primary" onclick={() => this.join(channel)}>
                    {app.translator.trans('ramon-chat.forum.channel.join')}
                  </Button>
                ) : null}
              </>
            )}
          </div>
        </div>
      </article>
    );
  }

  /**
   * Botão redondo só com ícone. O label serve de tooltip e de nome acessível.
   */
  protected iconButton(icon: string, labelKey: string, onclick: () => void, danger = false): Mithril.Children {
    const label = app.translator.trans(labelKey, {}, true) as string;

    return (
      <button
        type="button"
        className={classList('ChatBrowseCard-iconButton', {
          'ChatBrowseCard-iconButton--danger': danger,
        })}
        title={label}
        aria-label={label}
        onclick={onclick}
      >
        <i className={icon} aria-hidden="true" />
      </button>
    );
  }

  // ── Comportamento ──

  protected setFilter(filter: BrowseFilter): void {
    this.filter = filter;
    this.load();
  }

  /** Com debounce, para não disparar uma requisição por tecla. */
  protected onSearch(value: string): void {
    this.query = value;

    if (this.searchTimer !== null) window.clearTimeout(this.searchTimer);

    this.searchTimer = window.setTimeout(() => this.load(), 350);
  }

  /** Volta à lista sem filtro, sem esperar o debounce. */
  protected clearSearch(): void {
    if (this.searchTimer !== null) {
      window.clearTimeout(this.searchTimer);
      this.searchTimer = null;
    }

    this.query = '';
    this.load();
  }

  protected async load(): Promise<void> {
    this.loading = true;
    m.redraw();

    const filter: Record<string, unknown> = {};

    // 'all' não envia filtro de status: canais fechados e arquivados também aparecem.
    if (this.filter === 'open' || this.filter === 'closed' || this.filter === 'archived') {
      filter.status = this.filter;
    }

    if (this.filter === 'mine') {
      filter.following = true;
    }

    if (this.query.trim() !== '') {
      filter.q = this.query.trim();
    }

    try {
      const results = (await app.store.find('chat-channels', {
        filter,
        sort: '-lastMessageAt',
        page: { limit: 50 },
      })) as unknown as Channel[];

      // Canais diretos são privados por natureza e não aparecem aqui.
      this.channels = (Array.isArray(results) ? results : []).filter((c) => c.isCategory());
    } catch (e) {
      this.channels = [];
      // Não `browse.empty`: falha de requisição não é fórum sem canais.
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.browse.load_failed'));
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  protected create(): void {
    app.modal.show(loadChannelFormModal, {
      onSaved: (channel: Channel) => this.open(channel),
    });
  }

  protected edit(channel: Channel): void {
    // Recarrega após salvar: o canal editado pode não casar mais com o filtro.
    app.modal.show(loadChannelFormModal, {
      channel,
      onSaved: () => this.load(),
    });
  }

  /**
   * Entra no canal ou, com `hidden`, inspeciona: sem aparecer na lista de membros
   * nem notificar. O servidor exige `inspectChannels` para o modo oculto.
   */
  protected async join(channel: Channel, hidden = false): Promise<void> {
    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-channels/${channel.id()}/join`,
        body: { data: { attributes: { hidden } } },
      });

      // Registro do servidor com as flags de permissão, para o canal abrir já com
      // o composer em vez do aviso de "fechado".
      if (!adoptChannelPayload(payload)) {
        channel.pushAttributes({
          isFollowing: true,
          isHiddenMember: hidden,
          canPostMessage: channel.isOpen(),
          userCount: hidden ? channel.userCount() : (channel.userCount() ?? 0) + 1,
        });
      }

      chatState.rememberChannel(channel);

      if (hidden) {
        app.alerts.show({ type: 'success' }, app.translator.trans('ramon-chat.forum.channel.joined_hidden'));
      }

      m.redraw();
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, invitationErrorText(e, 'ramon-chat.forum.channel.join_failed'));
    }
  }

  /**
   * Aceita ou recusa um convite pelo card. Recusar deixa o canal disponível para
   * entrar por conta própria.
   */
  protected async answerInvitation(channel: Channel, accept: boolean): Promise<void> {
    if (!accept && !confirm(app.translator.trans('ramon-chat.forum.channel.decline_invite_confirm', {}, true))) {
      return;
    }

    try {
      if (accept) {
        await acceptInvitation(Number(channel.id()));
      } else {
        await declineInvitation(Number(channel.id()));
      }

      app.alerts.show(
        { type: 'success' },
        app.translator.trans(accept ? 'ramon-chat.forum.channel.invite_accepted' : 'ramon-chat.forum.channel.invite_declined')
      );

      m.redraw();
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, invitationErrorText(e, 'ramon-chat.forum.channel.invite_failed'));
    }
  }

  protected open(channel: Channel): void {
    chatState.setActiveChannel(Number(channel.id()));
    m.route.set(app.route('chat.channel', { id: channel.id() }));
  }
}
