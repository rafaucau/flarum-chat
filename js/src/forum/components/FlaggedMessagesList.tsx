import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Avatar from 'flarum/common/components/Avatar';
import humanTime from 'flarum/common/helpers/humanTime';
import username from 'flarum/common/helpers/username';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type Message from '../../common/models/Message';
import type MessageFlag from '../../common/models/MessageFlag';
import type ChatState from '../state/ChatState';
import { MessageStreamSkeleton } from './Skeletons';
import { messagePreview } from '../../common/utils/preview';
import { authorAvatar, authorName } from '../utils/bot';

export interface FlaggedMessagesListAttrs extends ComponentAttrs {
  state: ChatState;
}

/**
 * Fila de moderação do chat.
 *
 * Não usa flarum/flags: `flags.post_id` é FK não nula para `posts`. Linhas são
 * resumos, como em BookmarksList.
 */
export default class FlaggedMessagesList extends Component<FlaggedMessagesListAttrs> {
  private flags: MessageFlag[] = [];
  private loading = true;
  /** Denúncia com requisição em andamento, para só essa linha mostrar spinner. */
  private working: number | null = null;
  /** Mostra também as denúncias resolvidas. */
  private showResolved = false;

  oninit(vnode: Mithril.Vnode<FlaggedMessagesListAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  view(): Mithril.Children {
    // A rota é acessível a qualquer usuário do chat; sem `ramon-chat.moderate`
    // recusa em vez de mostrar uma lista vazia que pareceria "sem denúncias".
    if (!app.forum.attribute<boolean>('canModerateChat')) {
      return (
        <div className="ChatFlags">
          <div className="ChatBrowse-empty">{app.translator.trans('ramon-chat.forum.flags.forbidden')}</div>
        </div>
      );
    }

    return (
      <div className="ChatFlags">
        <div className="ChatFlags-header">
          <h3 className="ChatFlags-title">{app.translator.trans('ramon-chat.forum.flags.title')}</h3>

          <Button
            className={classList('Button Button--flat ChatFlags-toggle', {
              'ChatFlags-toggle--active': this.showResolved,
            })}
            icon={this.showResolved ? 'fas fa-eye' : 'far fa-eye'}
            onclick={() => {
              this.showResolved = !this.showResolved;
              this.load();
            }}
          >
            {app.translator.trans('ramon-chat.forum.flags.show_resolved')}
          </Button>
        </div>

        {this.body()}
      </div>
    );
  }

  protected body(): Mithril.Children {
    if (this.loading) return MessageStreamSkeleton(4);

    if (this.flags.length === 0) {
      return (
        <div className="ChatBrowse-empty">
          {app.translator.trans(this.showResolved ? 'ramon-chat.forum.flags.empty_all' : 'ramon-chat.forum.flags.empty')}
        </div>
      );
    }

    return this.flags.map((flag) => this.row(flag));
  }

  protected row(flag: MessageFlag): Mithril.Children {
    const message = flag.message();
    const id = Number(flag.id());
    const resolved = Boolean(flag.isResolved());
    const at = flag.createdAt();

    return (
      <div
        className={classList('ChatFlags-row', {
          'ChatFlags-row--resolved': resolved,
        })}
        key={flag.id()}
      >
        <div className="ChatFlags-reporter">
          <Avatar user={flag.user()} className="Avatar" />

          <div className="ChatFlags-reporter-meta">
            <span className="ChatFlags-reporter-name">{username(flag.user())}</span>
            {at ? <span className="ChatFlags-time">{humanTime(at)}</span> : null}
          </div>

          <span className={`ChatFlags-reason ChatFlags-reason--${flag.reason()}`}>
            {app.translator.trans(`ramon-chat.forum.flag.reasons.${flag.reason()}`)}
          </span>
        </div>

        {/* Nó de texto, escapado pelo Mithril. Nunca m.trust aqui. */}
        {flag.detail() ? <div className="ChatFlags-detail">{flag.detail()}</div> : null}

        {message ? this.target(message) : <div className="ChatFlags-gone">{app.translator.trans('ramon-chat.forum.flags.message_gone')}</div>}

        <div className="ChatFlags-actions">
          {message ? (
            <Button className="Button Button--flat" icon="fas fa-arrow-right" onclick={() => this.open(message)}>
              {app.translator.trans('ramon-chat.forum.flags.go_to')}
            </Button>
          ) : null}

          {resolved ? (
            <span className="ChatFlags-resolvedBy">
              {app.translator.trans('ramon-chat.forum.flags.resolved_by', {
                // Não `user`: o translator reserva essa chave e roda username() nela,
                // o que quebra com um vnode.
                moderator: username(flag.resolvedBy()),
              })}
            </span>
          ) : (
            [
              message && !message.isDeleted() && message.canDelete() ? (
                <Button
                  key="delete"
                  className="Button Button--flat ChatFlags-delete"
                  icon="fas fa-trash"
                  loading={this.working === id}
                  onclick={() => this.deleteMessage(flag, message)}
                >
                  {app.translator.trans('ramon-chat.forum.flags.delete_message')}
                </Button>
              ) : null,

              <Button
                key="resolve"
                className="Button Button--flat"
                icon="fas fa-check"
                loading={this.working === id}
                onclick={() => this.resolve(flag)}
              >
                {app.translator.trans('ramon-chat.forum.flags.dismiss')}
              </Button>,
            ]
          )}
        </div>
      </div>
    );
  }

  protected target(message: Message): Mithril.Children {
    const channel = this.attrs.state.channel(message.channelId());

    return (
      <div className="ChatFlags-target">
        <div className="ChatFlags-target-meta">
          {authorAvatar(message, 'Avatar Avatar--small')}
          <span className="ChatFlags-target-author">{authorName(message)}</span>
          {channel ? <span className="ChatFlags-target-channel">{channel.displayName()}</span> : null}
        </div>

        {/* Texto puro, como no modal: o conteúdo denunciado pode ser o problema. */}
        <div className="ChatFlags-target-excerpt">
          {message.isDeleted() ? app.translator.trans('ramon-chat.forum.flags.message_deleted') : messagePreview(message, 240)}
        </div>
      </div>
    );
  }

  protected async load(): Promise<void> {
    this.loading = true;
    m.redraw();

    try {
      const results = (await app.store.find('chat-message-flags', {
        // Sempre enviado: o servidor não tem default (um filtro posterior não
        // desfaria um default do searcher). É `filter[...]` porque o Flarum 2
        // rejeita parâmetros que não conhece.
        filter: { resolved: this.showResolved ? '1' : '0' },
        sort: '-createdAt',
        page: { limit: 50 },
      })) as unknown as MessageFlag[];

      this.flags = Array.isArray(results) ? results : [];
    } catch {
      this.flags = [];

      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.flags.load_failed'));
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

  protected async resolve(flag: MessageFlag): Promise<void> {
    const id = Number(flag.id());

    this.working = id;
    m.redraw();

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-message-flags/${id}/resolve`,
      });

      this.afterResolved(flag);
    } catch {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.flags.action_failed'));
    } finally {
      this.working = null;
      m.redraw();
    }
  }

  /**
   * Apaga a mensagem denunciada; o servidor fecha junto todas as denúncias abertas
   * dela (ResolveFlagsOnModeration).
   */
  protected async deleteMessage(flag: MessageFlag, message: Message): Promise<void> {
    if (!confirm(app.translator.trans('ramon-chat.forum.message.delete_confirm', {}, true))) return;

    const id = Number(flag.id());

    this.working = id;
    m.redraw();

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-messages/${message.id()}/delete`,
      });

      // Sempre remoção por moderador: o autor não acessa a fila.
      message.pushAttributes({
        isDeleted: true,
        content: null,
        contentHtml: null,
        isModeratorDeleted: true,
      });

      this.afterResolved(flag);
    } catch {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.message.delete_failed'));
    } finally {
      this.working = null;
      m.redraw();
    }
  }

  /**
   * Remove a linha da fila aberta, ou a marca no lugar quando as resolvidas estão
   * visíveis.
   */
  protected afterResolved(flag: MessageFlag): void {
    flag.pushAttributes({ isResolved: true, resolvedAt: new Date() });

    if (!this.showResolved) {
      this.flags = this.flags.filter((f) => f.id() !== flag.id());
    }

    // Atualiza o badge da sidebar sem nova requisição.
    const count = Number(app.forum.attribute<number>('chatOpenFlagsCount') ?? 0);

    app.forum.pushAttributes({ chatOpenFlagsCount: Math.max(0, count - 1) });
  }
}
