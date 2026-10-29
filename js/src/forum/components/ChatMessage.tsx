import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import username from 'flarum/common/helpers/username';
import humanTime from 'flarum/common/helpers/humanTime';
import humanTimeUtil from 'flarum/common/utils/humanTime';
import listItems from 'flarum/common/helpers/listItems';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
import { displayEmoji } from '../utils/emoji';
import { customEmoji, customEmojiImage } from '../utils/flamoji';
import { isOnline } from '../utils/presence';
import { authorAvatar, authorLink } from '../utils/bot';
import { safeFileUrl } from '../utils/url';
import { jumpToMessage, revealAndJump } from '../utils/jumpToMessage';
import { verifiedBadge } from '../utils/integrations';
import { messageRank, rankNameAttrs, rankTag } from '../utils/ranks';
import { messagePreview } from '../../common/utils/preview';
import { refreshMessageCapabilities } from '../realtime';
import iconLabel from '../utils/iconLabel';
import { loadFlagMessageModal, loadImageLightbox } from '../utils/lazy';

export interface ChatMessageAttrs extends ComponentAttrs {
  message: Message;
  /** Linha anterior, usada para agrupar. */
  previous?: Message | null;
  state: ChatState;
  onReply?: (message: Message) => void;
  onEdit?: (message: Message) => void;
  onOpenThread?: (message: Message) => void;
  /** Esconde a faixa "N respostas"; usado dentro do próprio painel da thread. */
  hideThreadIndicator?: boolean;
}

/**
 * Reação de um clique na barra de hover. `+1` (👍), como o Like de um post no
 * Flarum; os demais emojis ficam no seletor.
 */
const LIKE_REACTION = '+1';

/**
 * Uma linha do stream. Agrupamento, destaque de menção e lápides vêm do model,
 * para uma linha vinda do realtime ficar igual a uma vinda de fetch.
 */
export default class ChatMessage extends Component<ChatMessageAttrs> {
  view(): Mithril.Children {
    const { message, previous, state } = this.attrs;

    const deleted = Boolean(message.isDeleted());
    // Denúncias abertas; só moderadores recebem.
    const reports = deleted ? 0 : Number(message.flagsCount() ?? 0);

    // Fixada ou denunciada não agrupa: o agrupamento esconde a linha de meta.
    const grouped = message.isGroupedWith(previous) && !message.isPinned() && reports === 0;
    const selected = state.selected.has(Number(message.id()));
    const rank = grouped ? null : messageRank(message);

    if (message.isSystem()) {
      return this.systemRow(message);
    }

    return (
      <div
        className={classList('ChatMessage', {
          'ChatMessage--grouped': grouped,
          'ChatMessage--pinned': !deleted && Boolean(message.isPinned()),
          'ChatMessage--flagged': reports > 0,
          'ChatMessage--mentioned': !deleted && message.mentionsActor(),
          'ChatMessage--selected': selected,
          // Post do bot que anuncia algo de fora (hoje, só nova discussão). Só o
          // conteúdo vira card; o resto é uma mensagem comum.
          'ChatMessage--announcement': !deleted && message.isBot() && Boolean(message.systemKey()),
          // Só em linha não agrupada, que é a que mostra avatar.
          'ChatMessage--online': !grouped && isOnline(message.user() || null),
        })}
        data-id={message.id()}
        onclick={state.selecting ? () => state.toggleSelected(Number(message.id())) : undefined}
      >
        <div className="ChatMessage-gutter">{grouped ? this.shortTime(message) : this.avatar(message)}</div>

        <div className="ChatMessage-body">
          {grouped ? null : (
            <div className="ChatMessage-meta">
              {/* Rank do autor no canal, antes do nome. Sem tag, o rank colore o nome. */}
              {rank?.showBadge ? rankTag(rank, 'ChatMessage-rank') : null}

              <span {...rankNameAttrs(rank)} className={classList('ChatMessage-author', rankNameAttrs(rank).className)}>
                {authorLink(message)}
              </span>

              {/* ramon/verified, se instalado, logo após o nome como nos posts. */}
              {message.isBot() ? null : verifiedBadge(message.user())}

              {/* Deixa explícito que ninguém digitou esta mensagem. */}
              {message.isBot() ? <span className="ChatMessage-botTag">{app.translator.trans('ramon-chat.forum.bot.tag')}</span> : null}
              {message.createdAt() ? <span className="ChatMessage-time">{humanTime(message.createdAt()!)}</span> : null}
              {/* Fixar vale para o canal todo, por isso aparece também na linha. */}
              {!deleted && message.isPinned() ? (
                <span className="ChatMessage-pinMark" title={String(message.pinnedAt() ?? '')}>
                  <i className="fas fa-thumbtack" aria-hidden="true" />
                  {app.translator.trans('ramon-chat.forum.message.pinned')}
                </span>
              ) : null}
              {/* Para moderadores: quais mensagens têm denúncia pendente. */}
              {reports > 0 ? (
                <Link
                  className="ChatMessage-flagMark"
                  href={app.route('chat.flags')}
                  title={app.translator.trans('ramon-chat.forum.message.reports_open', { count: reports }, true)}
                  onclick={(e: MouseEvent) => e.stopPropagation()}
                >
                  <i className="fas fa-flag" aria-hidden="true" />
                  {app.translator.trans('ramon-chat.forum.message.reports', {
                    count: reports,
                  })}
                </Link>
              ) : null}
            </div>
          )}

          {this.replyPreview(message)}
          {deleted ? this.tombstone(message) : this.content(message)}
          {/* Anexos antes das reações: a reação é sobre a mensagem inteira. Figurinhas
              usam o mesmo bloco. */}
          {deleted ? null : this.uploads(message)}
          {deleted ? null : this.reactions(message)}
          {deleted ? null : this.threadIndicator(message)}
        </div>

        {state.selecting ? null : this.actions(message)}
      </div>
    );
  }

  /**
   * Mensagens apagadas mantêm o lugar no stream; removê-las deixaria as respostas
   * a elas sem sentido.
   */
  protected tombstone(message: Message): Mithril.Children {
    // Decide por quem removeu, não por o texto estar oculto: mensagem apagada pelo
    // autor também fica oculta para os demais.
    if (!message.isModeratorDeleted()) {
      return (
        <div className="ChatMessage-tombstone">
          <span>{app.translator.trans('ramon-chat.forum.message.deleted')}</span>

          {this.purgeButton(message)}
        </div>
      );
    }

    // `hasOne` devolve false sem a relação incluída (push do realtime não traz
    // relações); aí vale o texto sem nome.
    const moderator = message.deletedBy() || null;

    // Nomeia o moderador para o autor saber a quem perguntar.
    return (
      <div className="ChatMessage-tombstone">
        <span>
          {moderator
            ? app.translator.trans('ramon-chat.forum.message.deleted_by_named', {
                username: username(moderator),
              })
            : app.translator.trans('ramon-chat.forum.message.deleted_by_moderator')}
        </span>

        {this.purgeButton(message)}
      </div>
    );
  }

  /**
   * Imagem da discussão anunciada, no topo do card. Só em anúncios do bot.
   *
   * A URL é filtrada de novo (só http/https) mesmo o servidor já filtrando: vai
   * parar num `src`.
   */
  protected announcementIcon(message: Message): Mithril.Children {
    if (!message.isBot() || !message.systemKey()) return null;

    const data = message.systemData() as { image?: string } | null;
    const url = safeFileUrl(data?.image);

    if (!url) return null;

    return (
      <img
        className="ChatMessage-announcementIcon"
        src={url}
        alt=""
        loading="lazy"
        // Remove a imagem quebrada em vez de deixar o ícone de erro no card.
        onerror={(e: Event) => (e.target as HTMLElement)?.remove()}
      />
    );
  }

  /**
   * Remove a linha de vez. Fica na lápide, não na barra de hover, longe de
   * "responder" e "reagir", por ser irreversível.
   */
  protected purgeButton(message: Message): Mithril.Children {
    if (!message.canForceDelete()) return null;

    return (
      <Button
        className="Button Button--flat Button--icon ChatMessage-purge"
        icon="fas fa-eraser"
        {...iconLabel(app.translator.trans('ramon-chat.forum.message.purge', {}, true))}
        onclick={() => this.purge(message)}
      />
    );
  }

  protected async purge(message: Message): Promise<void> {
    if (!confirm(app.translator.trans('ramon-chat.forum.message.purge_confirm', {}, true))) {
      return;
    }

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-messages/${message.id()}/purge`,
      });

      this.attrs.state.removeMessage(Number(message.channelId()), Number(message.id()));
    } catch {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.message.purge_failed'));
    } finally {
      m.redraw();
    }
  }

  protected content(message: Message): Mithril.Children {
    const html = message.contentHtml();

    return (
      <div className="ChatMessage-content">
        {this.announcementIcon(message)}
        {/* `contentHtml` é renderizado pelo servidor via `HasFormattedContent`
            (s9e/TextFormatter), como o corpo de um post; o core confia nele do mesmo
            jeito. O `content` cru nunca passa por m.trust: vira nó de texto. */}
        {/* nosemgrep: github.semgrep.flarum-v2-m-trust */}
        {html ? m.trust(html) : message.content()}
        {/* Marcador "editada" como <time>: data exata em `datetime`, relativa no texto. */}
        {message.isEdited() ? this.editedMark(message) : null}
      </div>
    );
  }

  /**
   * Marcador "editada". `editedAt` pode faltar numa linha atualizada pelo socket
   * antes da API responder; aí mostra só a palavra.
   */
  protected editedMark(message: Message): Mithril.Children {
    const at = message.editedAt();

    if (!at) {
      return <span className="ChatMessage-editedMark">({app.translator.trans('ramon-chat.forum.message.edited')})</span>;
    }

    return (
      <time
        className="ChatMessage-editedMark"
        datetime={at.toISOString()}
        title={app.translator.formatDateTime(dayjs(at), 'core.lib.datetime_formats.fullTime')}
      >
        (
        {app.translator.trans('ramon-chat.forum.message.edited_at', {
          ago: humanTimeUtil(at),
        })}
        )
      </time>
    );
  }

  protected systemRow(message: Message): Mithril.Children {
    const key = message.systemKey();

    return (
      <div className="ChatMessage ChatMessage--system">
        <div className="ChatMessage-gutter" />
        <div className="ChatMessage-body">
          <div className="ChatMessage-tombstone">
            {key ? app.translator.trans(`ramon-chat.forum.message.system.${key}`, message.systemData() ?? {}) : null}
          </div>
        </div>
      </div>
    );
  }

  /**
   * Abre o visualizador de imagens. Montado num nó do body: dentro do stream
   * (`overflow: auto`) o overlay seria cortado.
   */
  protected async openLightbox(images: any[], index: number): Promise<void> {
    const ImageLightbox = (await loadImageLightbox()).default;

    const mount = document.createElement('div');
    document.body.appendChild(mount);

    const close = () => {
      m.mount(mount, null);
      mount.remove();
    };

    m.mount(mount, {
      view: () =>
        m(ImageLightbox, {
          uploads: images,
          index: Math.max(0, index),
          message: this.attrs.message,
          onClose: close,
        }),
    });
  }

  /**
   * Avatar com os badges de grupo. Usa `user.badges()` como o `PostUser`, então
   * extensões que estendem `User.prototype.badges` aparecem aqui também. O wrapper
   * só existe quando há badge.
   */
  protected avatar(message: Message): Mithril.Children {
    // O bot não tem conta nem grupos.
    const user = message.isBot() ? null : message.user() || null;
    const badges = user ? user.badges().toArray() : [];

    if (!badges.length) return authorAvatar(message);

    return (
      <span className="ChatMessage-avatar">
        {authorAvatar(message)}
        <ul className="ChatMessage-badges badges badges--packed">{listItems(badges)}</ul>
      </span>
    );
  }

  protected shortTime(message: Message): Mithril.Children {
    const at = message.createdAt();

    if (!at) return null;

    return (
      <span>
        {at.getHours().toString().padStart(2, '0')}:{at.getMinutes().toString().padStart(2, '0')}
      </span>
    );
  }

  /**
   * Prévia da mensagem respondida. O clique pula para a original ou avisa se ela
   * está fora da janela carregada. É `button` para funcionar no teclado; o clique
   * no nome do autor segue para o perfil.
   */
  protected replyPreview(message: Message): Mithril.Children {
    const target = message.replyTo();

    if (!target) return null;

    return (
      <button
        type="button"
        className="ChatMessage-replyTo"
        title={app.translator.trans('ramon-chat.forum.message.reply_jump', {}, true)}
        onclick={(e: MouseEvent) => {
          e.stopPropagation();

          if ((e.target as HTMLElement | null)?.closest('a')) return;

          const from = e.currentTarget as HTMLElement;
          const inThread = !!from.closest('.ChatThreadPanel-stream');
          const jumped = inThread
            ? Promise.resolve(jumpToMessage(target.id()!, from))
            : revealAndJump(this.attrs.state, Number(message.channelId()), target.id()!, from);

          jumped.then((found) => {
            if (found) return;

            app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.message.reply_not_loaded'));
          });
        }}
      >
        <i className="fas fa-reply" aria-hidden="true" />
        <span>{authorLink(target)}</span>
        <span className="ChatMessage-replyTo-content">{messagePreview(target, 120)}</span>
      </button>
    );
  }

  protected reactions(message: Message): Mithril.Children {
    const summary = message.reactionSummary() ?? {};
    const emojis = Object.keys(summary);

    if (emojis.length === 0) return null;

    return (
      <div className="ChatReactions">
        {emojis.map((emoji) => {
          const entry = summary[emoji];
          // A reação é gravada como shortcode; emoji customizado do Flamoji precisa
          // ser resolvido para não aparecer como `:kappa:`.
          const custom = customEmoji(emoji);

          return (
            <button
              key={emoji}
              type="button"
              className={classList('ChatReactions-chip', {
                'ChatReactions-chip--active': entry.reacted,
              })}
              disabled={!message.canReact()}
              onclick={(e: Event) => {
                e.stopPropagation();
                this.react(message, emoji);
              }}
            >
              {/* Like como ícone, não 👍: igual ao flarum/likes e herda a cor do chip. */}
              {emoji === LIKE_REACTION ? (
                <i className="ChatReactions-icon fas fa-thumbs-up" aria-hidden="true" />
              ) : custom ? (
                <span className="ChatReactions-emoji">{customEmojiImage(custom, 'ChatReactions-flamoji')}</span>
              ) : (
                <span className="ChatReactions-emoji">{displayEmoji(emoji)}</span>
              )}
              <span>{entry.count}</span>
            </button>
          );
        })}
      </div>
    );
  }

  protected uploads(message: Message): Mithril.Children {
    // `hasMany` devolve `false` sem carregar, e itens podem faltar no store.
    const related = message.uploads();

    if (!related) return null;

    const uploads = related.filter((upload): upload is NonNullable<typeof upload> => Boolean(upload));

    if (uploads.length === 0) return null;

    // O visualizador navega só entre imagens.
    const images = uploads.filter((upload) => upload.isImage());

    return (
      <div className="ChatUploads">
        {uploads.map((upload, position) =>
          upload.isImage() ? (
            // Botão, não link: abre o visualizador sem sair da conversa.
            <button
              type="button"
              key={upload.id()}
              className="ChatUploads-imageButton"
              onclick={(e: Event) => {
                e.stopPropagation();
                this.openLightbox(images, images.indexOf(upload)).catch(() => {});
              }}
              aria-label={upload.fileName() ?? ''}
            >
              <img
                className="ChatUploads-image"
                src={safeFileUrl(upload.url())}
                alt={upload.fileName()}
                // Dimensões gravadas, para a linha não pular ao carregar a imagem.
                width={upload.width() ?? undefined}
                height={upload.height() ?? undefined}
                loading="lazy"
              />
            </button>
          ) : (
            <a key={upload.id()} className="ChatUploads-file" href={safeFileUrl(upload.url())} target="_blank" rel="noopener noreferrer">
              <i className="fas fa-paperclip" aria-hidden="true" />
              <span className="ChatUploads-file-name">{upload.fileName()}</span>
              <span className="ChatUploads-file-size">{upload.humanSize()}</span>
            </a>
          )
        )}
      </div>
    );
  }

  protected threadIndicator(message: Message): Mithril.Children {
    if (this.attrs.hideThreadIndicator) return null;

    const thread = message.thread();

    // Só a raiz da thread tem o indicador.
    if (!thread || thread.originalMessageId() !== Number(message.id())) return null;

    const last = thread.lastMessage();

    return (
      <button
        type="button"
        className="ChatThreadIndicator"
        onclick={(e: Event) => {
          e.stopPropagation();
          this.attrs.onOpenThread?.(message);
        }}
      >
        <i className="fas fa-comments" aria-hidden="true" />
        <span className="ChatThreadIndicator-count">
          {app.translator.trans('ramon-chat.forum.thread.replies', {
            count: thread.repliesCount(),
          })}
        </span>
        {last ? <span className="ChatThreadIndicator-preview">{messagePreview(last, 80)}</span> : null}
      </button>
    );
  }

  protected actions(message: Message): Mithril.Children {
    const items: Mithril.Children[] = [];

    if (message.canReact()) {
      const liked = Boolean(message.reactionSummary()?.[LIKE_REACTION]?.reacted);

      items.push(
        <Button
          className={classList('ChatMessage-action', {
            'ChatMessage-action--active': liked,
          })}
          // Cheio quando curtido, contorno quando não, como no flarum/likes.
          icon={liked ? 'fas fa-thumbs-up' : 'far fa-thumbs-up'}
          {...iconLabel(app.translator.trans(liked ? 'ramon-chat.forum.message.unlike' : 'ramon-chat.forum.message.like', {}, true))}
          onclick={() => this.react(message, LIKE_REACTION)}
        />
      );
    }

    if (message.canCreateThread()) {
      items.push(
        <Button
          className="ChatMessage-action"
          icon="fas fa-comments"
          {...iconLabel(app.translator.trans('ramon-chat.forum.message.reply_in_thread', {}, true))}
          onclick={() => this.attrs.onOpenThread?.(message)}
        />
      );
    }

    if (message.canReply()) {
      items.push(
        <Button
          className="ChatMessage-action"
          icon="fas fa-reply"
          {...iconLabel(app.translator.trans('ramon-chat.forum.message.reply', {}, true))}
          onclick={() => this.attrs.onReply?.(message)}
        />
      );
    }

    if (message.canEdit()) {
      items.push(
        <Button
          className="ChatMessage-action"
          icon="fas fa-pencil"
          {...iconLabel(app.translator.trans('ramon-chat.forum.message.edit', {}, true))}
          onclick={() => this.attrs.onEdit?.(message)}
        />
      );
    }

    if (app.session.user) {
      items.push(
        <Button
          className="ChatMessage-action"
          icon={message.isBookmarked() ? 'fas fa-bookmark' : 'far fa-bookmark'}
          {...iconLabel(
            app.translator.trans(message.isBookmarked() ? 'ramon-chat.forum.message.remove_bookmark' : 'ramon-chat.forum.message.bookmark', {}, true)
          )}
          onclick={() => this.bookmark(message)}
        />
      );
    }

    // Modo de seleção em toda linha legível: só mover exige moderação, e isso é
    // checado na barra de seleção.
    items.push(
      <Button
        className="ChatMessage-action"
        icon="fas fa-list-check"
        {...iconLabel(app.translator.trans('ramon-chat.forum.message.select', {}, true))}
        onclick={() => {
          const state = this.attrs.state;

          state.selecting = true;
          state.selected.add(Number(message.id()));
          m.redraw();
        }}
      />
    );

    if (message.canPin()) {
      const pinned = Boolean(message.isPinned());

      items.push(
        <Button
          className={classList('ChatMessage-action', {
            'ChatMessage-action--active': pinned,
          })}
          // O ícone mostra o que o clique faz (desafixar), não o estado atual.
          icon={pinned ? 'fas fa-thumbtack-slash' : 'fas fa-thumbtack'}
          {...iconLabel(app.translator.trans(pinned ? 'ramon-chat.forum.message.unpin' : 'ramon-chat.forum.message.pin', {}, true))}
          onclick={() => this.pin(message)}
        />
      );
    }

    // Denunciar fica por último: gera trabalho para um moderador.
    if (message.canFlag()) {
      const reported = Boolean(message.isFlagged());

      items.push(
        <Button
          className={classList('ChatMessage-action', {
            'ChatMessage-action--active': reported,
          })}
          icon="fas fa-flag"
          {...iconLabel(app.translator.trans(reported ? 'ramon-chat.forum.message.flagged' : 'ramon-chat.forum.message.flag', {}, true))}
          onclick={() => app.modal.show(loadFlagMessageModal, { message })}
        />
      );
    }

    if (message.canDelete()) {
      items.push(
        <Button
          className="ChatMessage-action"
          icon="fas fa-trash"
          {...iconLabel(app.translator.trans('ramon-chat.forum.message.delete', {}, true))}
          onclick={() => this.delete(message)}
        />
      );
    }

    if (items.length === 0) return null;

    return <div className="ChatMessage-actions">{items}</div>;
  }

  // ── Ações ──

  protected async react(message: Message, emoji: string): Promise<void> {
    // Otimista: reação é a interação mais frequente.
    const summary = { ...(message.reactionSummary() ?? {}) };
    const entry = summary[emoji] ?? { count: 0, reacted: false };

    summary[emoji] = {
      count: entry.count + (entry.reacted ? -1 : 1),
      reacted: !entry.reacted,
    };

    if (summary[emoji].count <= 0) delete summary[emoji];

    message.pushAttributes({ reactionSummary: summary });
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-messages/${message.id()}/react`,
        body: { data: { attributes: { emoji } } },
      });

      app.store.pushPayload(payload);
    } catch (e) {
      // Volta à versão do servidor em vez de adivinhar o rollback.
      message.pushAttributes({ reactionSummary: message.reactionSummary() });
    } finally {
      m.redraw();
    }
  }

  protected async bookmark(message: Message): Promise<void> {
    const was = Boolean(message.isBookmarked());

    message.pushAttributes({ isBookmarked: !was });
    m.redraw();

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-messages/${message.id()}/bookmark`,
        body: { data: { attributes: {} } },
      });
    } catch {
      message.pushAttributes({ isBookmarked: was });
    } finally {
      m.redraw();
    }
  }

  /**
   * Alterna a fixação, de forma otimista; numa falha volta ao estado do servidor.
   */
  protected async pin(message: Message): Promise<void> {
    const was = Boolean(message.isPinned());

    message.pushAttributes({ isPinned: !was });
    m.redraw();

    try {
      const payload = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-messages/${message.id()}/pin`,
        body: { data: { attributes: {} } },
      });

      app.store.pushPayload(payload);
    } catch (e: any) {
      message.pushAttributes({ isPinned: was });

      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.message.pin_failed'));
    } finally {
      m.redraw();
    }
  }

  protected async delete(message: Message): Promise<void> {
    if (!confirm(app.translator.trans('ramon-chat.forum.message.delete_confirm', {}, true))) return;

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat-messages/${message.id()}/delete`,
      });

      message.pushAttributes({
        isDeleted: true,
        content: null,
        contentHtml: null,
        // Definido já: a exclusão é otimista e a lápide precisa saber quem removeu.
        isModeratorDeleted: Number((message.user() || null)?.id() ?? 0) !== Number(app.session.user?.id() ?? 0),
      });

      // `canForceDelete` e afins só o servidor responde. O broadcast de mensagem
      // alterada exclui quem agiu, então o realtime não atualiza este usuário.
      refreshMessageCapabilities(Number(message.id()));
    } catch (e) {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.message.delete_failed'));
    } finally {
      m.redraw();
    }
  }
}
