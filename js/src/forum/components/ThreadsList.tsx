import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import humanTime from 'flarum/common/helpers/humanTime';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type Thread from '../../common/models/Thread';
import type ChatState from '../state/ChatState';
import { ThreadsSkeleton } from './Skeletons';
import iconLabel from '../utils/iconLabel';

export interface ThreadsListAttrs extends ComponentAttrs {
  state: ChatState;
  /** Todas as threads de um canal, em vez das do usuário em todos os canais. */
  channelId?: number | null;
  /** Desenhado sobre a conversa no drawer, com cabeçalho próprio. */
  embedded?: boolean;
  /** Fecha o painel. Obrigatório com embedded; ignorado sem. */
  onClose?: () => void;
}

/**
 * Threads do usuário em todos os canais, ou todas as de um canal. Na página a
 * linha abre a thread por rota; no drawer, pelo state, porque rotear fecha o drawer.
 */
export default class ThreadsList extends Component<ThreadsListAttrs> {
  private threads: Thread[] = [];
  private loading = true;

  oninit(vnode: Mithril.Vnode<ThreadsListAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<ThreadsListAttrs, this>): void {
    const previous = this.attrs?.channelId ?? null;

    super.onbeforeupdate(vnode);

    // A página mantém uma única instância entre rotas do chat (ChatPageResolver),
    // então o escopo pode mudar sem remontar.
    if ((vnode.attrs.channelId ?? null) !== previous) {
      this.loading = true;
      this.load();
    }
  }

  view(): Mithril.Children {
    return (
      <div
        className={classList('ChatThreadsList', {
          'ChatThreadsList--embedded': this.attrs.embedded,
        })}
      >
        {this.header()}
        {this.body()}
      </div>
    );
  }

  /**
   * Título e, no drawer, volta para a conversa. Na página só a lista de um canal
   * tem título; as seções já o mostram na toolbar.
   */
  protected header(): Mithril.Children {
    const scoped = Boolean(this.attrs.channelId);

    if (!this.attrs.embedded && !scoped) return null;

    const channel = scoped ? this.attrs.state.channel(Number(this.attrs.channelId)) : null;

    // Mesma casca dos painéis de thread, fixadas e busca, que dividem o slot do drawer.
    return (
      <div className="ChatThreadPanel-header">
        <i className="fas fa-code-branch" aria-hidden="true" />

        <span className="ChatThreadPanel-title">
          {app.translator.trans('ramon-chat.forum.channel.threads')}
          {channel ? <span className="ChatThreadsList-scope">{channel.displayName()}</span> : null}
        </span>

        {this.attrs.embedded ? (
          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-xmark"
            {...iconLabel(app.translator.trans('ramon-chat.forum.search.close', {}, true))}
            onclick={this.attrs.onClose}
          />
        ) : null}
      </div>
    );
  }

  protected body(): Mithril.Children {
    if (this.loading) return ThreadsSkeleton();

    if (this.threads.length === 0) {
      return (
        <div className="ChatBrowse-empty">
          {app.translator.trans(this.attrs.channelId ? 'ramon-chat.forum.thread.no_threads_in_channel' : 'ramon-chat.forum.thread.no_threads')}
        </div>
      );
    }

    return <div className="ChatThreadsList-rows">{this.threads.map((thread) => this.row(thread))}</div>;
  }

  protected row(thread: Thread): Mithril.Children {
    const channel = this.attrs.state.channel(thread.channelId());
    const at = thread.lastMessageAt();

    return (
      <button
        type="button"
        key={thread.id()}
        className={classList('ChatThreadsList-row', {
          'ChatThreadsList-row--unread': thread.hasUnread(),
        })}
        onclick={() => this.open(thread)}
      >
        <i className="ChatThreadsList-icon fas fa-comments" aria-hidden="true" />

        <div className="ChatThreadsList-body">
          <div className="ChatThreadsList-title">{thread.displayTitle()}</div>

          <div className="ChatThreadsList-meta">
            {/* Nome do canal só quando a lista abrange vários canais. */}
            {channel && !this.attrs.channelId ? <span>{channel.displayName()}</span> : null}
            <span>
              {app.translator.trans('ramon-chat.forum.thread.replies', {
                count: thread.repliesCount(),
              })}
            </span>
            {at ? <span>{humanTime(at)}</span> : null}
          </div>
        </div>

        {thread.hasUnread() ? <span className="ChatThreadsList-badge">{thread.unreadCount()}</span> : null}
      </button>
    );
  }

  protected async load(): Promise<void> {
    const channelId = this.attrs.channelId ?? null;

    try {
      const results = (await app.store.find('chat-threads', {
        filter: channelId ? { channel: channelId } : { participating: true },
        sort: '-lastMessageAt',
        page: { limit: 50 },
      })) as unknown as Thread[];

      // O escopo mudou durante a requisição; a resposta não vale mais.
      if ((this.attrs.channelId ?? null) !== channelId) return;

      this.threads = Array.isArray(results) ? results : [];
    } catch {
      this.threads = [];
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  protected open(thread: Thread): void {
    const channelId = thread.channelId();

    if (!channelId) return;

    this.attrs.state.setActiveChannel(channelId);

    // No drawer o painel da thread ocupa o slot desta lista; fecha a lista antes.
    if (this.attrs.embedded) {
      this.attrs.state.showThreads = false;
      this.attrs.state.activeThreadId = Number(thread.id());
      m.redraw();

      return;
    }

    this.attrs.state.activeThreadId = Number(thread.id());

    m.route.set(app.route('chat.thread', { id: channelId, threadId: thread.id() }));
  }
}
