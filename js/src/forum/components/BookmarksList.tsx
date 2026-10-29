import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';

import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
import { MessageStreamSkeleton } from './Skeletons';
import { messagePreview } from '../../common/utils/preview';
import { authorAvatar, authorName } from '../utils/bot';
import iconLabel from '../utils/iconLabel';

export interface BookmarksListAttrs extends ComponentAttrs {
  state: ChatState;
}

/**
 * Mensagens salvas pelo usuário, de todos os canais.
 *
 * Linhas são resumos, não ChatMessage: responder, editar e fixar só fazem sentido
 * junto da conversa. Aqui cada linha leva à mensagem no canal.
 */
export default class BookmarksList extends Component<BookmarksListAttrs> {
  private messages: Message[] = [];
  private loading = true;
  private working: number | null = null;

  oninit(vnode: Mithril.Vnode<BookmarksListAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  view(): Mithril.Children {
    if (this.loading) {
      return <div className="ChatBookmarks">{MessageStreamSkeleton(4)}</div>;
    }

    if (this.messages.length === 0) {
      return (
        <div className="ChatBookmarks">
          <div className="ChatBrowse-empty">{app.translator.trans('ramon-chat.forum.bookmarks.empty')}</div>
        </div>
      );
    }

    return <div className="ChatBookmarks">{this.messages.map((message) => this.row(message))}</div>;
  }

  protected row(message: Message): Mithril.Children {
    const channel = this.attrs.state.channel(message.channelId());
    const at = message.createdAt();
    const id = Number(message.id());

    return (
      <div className="ChatBookmarks-row" key={message.id()}>
        {authorAvatar(message)}

        <button type="button" className="ChatBookmarks-body" onclick={() => this.open(message)}>
          <div className="ChatBookmarks-meta">
            <span className="ChatBookmarks-author">{authorName(message)}</span>
            {channel ? <span className="ChatBookmarks-channel">{channel.displayName()}</span> : null}
            {at ? <span>{humanTime(at)}</span> : null}
          </div>

          {/* Texto puro: um bloco de código ou imagem dominaria a lista. */}
          <div className="ChatBookmarks-excerpt">{this.excerpt(message)}</div>
        </button>

        <Button
          className="Button Button--icon Button--flat ChatBookmarks-remove"
          icon="fas fa-bookmark"
          loading={this.working === id}
          {...iconLabel(app.translator.trans('ramon-chat.forum.bookmarks.remove', {}, true))}
          onclick={() => this.remove(message)}
        />
      </div>
    );
  }

  protected excerpt(message: Message): string {
    // Mesma prévia da barra de fixadas: mostra o título, não `**[title](/d/1)**`.
    const text = messagePreview(message, 160);

    if (text === '') {
      return app.translator.trans('ramon-chat.forum.bookmarks.no_text', {}, true);
    }

    return text;
  }

  protected async load(): Promise<void> {
    try {
      const results = (await app.store.find('chat-messages', {
        filter: { bookmarked: true },
        sort: '-createdAt',
        page: { limit: 50 },
      })) as unknown as Message[];

      this.messages = Array.isArray(results) ? results : [];
    } catch {
      this.messages = [];
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  /**
   * Abre o canal e, se houver, a thread: uma resposta de thread não está na janela
   * do canal.
   */
  protected open(message: Message): void {
    const channelId = message.channelId();

    if (!channelId) return;

    const threadId = message.threadId();

    this.attrs.state.setActiveChannel(channelId);
    this.attrs.state.activeThreadId = threadId ?? null;

    m.route.set(threadId ? app.route('chat.thread', { id: channelId, threadId }) : app.route('chat.channel', { id: channelId }));
  }

  protected async remove(message: Message): Promise<void> {
    const id = Number(message.id());

    this.working = id;
    m.redraw();

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-messages/${id}/bookmark`,
        body: { data: { attributes: {} } },
      });

      // O endpoint alterna; sucesso significa que deixou de estar salva.
      this.messages = this.messages.filter((m) => Number(m.id()) !== id);
      message.pushAttributes({ isBookmarked: false });
    } catch {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.bookmarks.remove_failed'));
    } finally {
      this.working = null;
      m.redraw();
    }
  }
}
