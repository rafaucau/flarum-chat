import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import type Message from '../../common/models/Message';
import type Thread from '../../common/models/Thread';
import type ChatState from '../state/ChatState';
import ChatMessage from './ChatMessage';
import ChatComposer from './ChatComposer';
import { MessageStreamSkeleton } from './Skeletons';
import iconLabel from '../utils/iconLabel';

export interface ThreadPanelAttrs extends ComponentAttrs {
  channel: Channel;
  threadId: number;
  state: ChatState;
  /** Chamado ao fechar o painel, para a rota largar a thread. */
  onClose: () => void;
}

/**
 * Respostas de uma thread, ao lado do canal.
 *
 * O filtro de canal da API omite as respostas, então só aparecem aqui. É um
 * ChannelView com `filter[thread]` e sem divisor de não lidas: thread não tem
 * marcador de leitura próprio.
 */
export default class ThreadPanel extends Component<ThreadPanelAttrs> {
  private scroller: HTMLElement | null = null;
  private pinned = true;
  private lastRenderedCount = 0;

  oninit(vnode: Mithril.Vnode<ThreadPanelAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<ThreadPanelAttrs, this>): void {
    const previousId = this.attrs?.threadId;

    super.onbeforeupdate(vnode);

    // Troca de thread na mesma instância.
    if (previousId !== undefined && vnode.attrs.threadId !== previousId) {
      this.pinned = true;
      this.lastRenderedCount = 0;
      this.load();
    }
  }

  oncreate(vnode: Mithril.VnodeDOM<ThreadPanelAttrs>): void {
    super.oncreate(vnode);

    this.scroller = vnode.dom.querySelector('.ChatThreadPanel-stream');
    this.scrollToBottom();
  }

  onupdate(): void {
    const count = this.attrs.state.threadStream(this.attrs.threadId).messages.length;

    if (count > this.lastRenderedCount && this.pinned) {
      this.scrollToBottom();
    }

    this.lastRenderedCount = count;
  }

  view(): Mithril.Children {
    const { channel, threadId, state, onClose } = this.attrs;
    const stream = state.threadStream(threadId);
    const thread = app.store.getById<Thread>('chat-threads', String(threadId));

    return (
      <div className="ChatThreadPanel">
        <div className="ChatThreadPanel-header">
          <i className="fas fa-comments" aria-hidden="true" />

          <span className="ChatThreadPanel-title">
            {/* displayTitle() cai num trecho da mensagem raiz se não houver título. */}
            {thread ? thread.displayTitle() : app.translator.trans('ramon-chat.forum.thread.title')}
          </span>

          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-xmark"
            {...iconLabel(app.translator.trans('ramon-chat.forum.thread.close', {}, true))}
            onclick={onClose}
          />
        </div>

        <div className="ChatThreadPanel-stream" onscroll={(e: Event) => this.onScroll(e)}>
          {stream.loading && stream.messages.length === 0 ? MessageStreamSkeleton(4) : null}

          {stream.loadedInitial && stream.messages.length === 0 ? (
            <div className="ChatBrowse-empty">{app.translator.trans('ramon-chat.forum.thread.no_replies')}</div>
          ) : null}

          {/* ChatMessage desenha os botões pelas flags da mensagem, então onReply/onEdit
              precisam estar ligados. Sem `onOpenThread`: a policy não aninha threads,
              e canCreateThread é false em toda linha daqui. */}
          {stream.messages.map((message, index) => (
            <ChatMessage
              key={message.id()}
              message={message}
              previous={stream.messages[index - 1] ?? null}
              state={state}
              hideThreadIndicator
              onReply={(msg: Message) => this.reply(msg)}
              onEdit={(msg: Message) => this.edit(msg)}
            />
          ))}
        </div>

        <ChatComposer channel={channel} state={state} threadId={threadId} onSent={() => this.onSent()} />
      </div>
    );
  }

  protected load(): void {
    const { state, threadId } = this.attrs;

    // Para o título: pela URL, o painel abre sem o stream ter carregado a raiz.
    state.findThread(threadId).then(() => m.redraw());

    state.loadThread(threadId).catch(() => {});
  }

  protected onScroll(e: Event): void {
    const el = e.target as HTMLElement;

    this.pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 40;

    if (el.scrollTop < 120) {
      const stream = this.attrs.state.threadStream(this.attrs.threadId);

      if (stream.hasMore && !stream.loading) {
        this.attrs.state.fetchThreadPage(this.attrs.threadId).catch(() => {});
      }
    }
  }

  /** Resposta e edição ficam no escopo do painel, sem afetar o composer do canal. */
  protected reply(message: Message): void {
    const { state, channel, threadId } = this.attrs;

    state.setReplyingTo(Number(channel.id()), message, threadId);
    m.redraw();
  }

  protected edit(message: Message): void {
    const { state, channel, threadId } = this.attrs;

    state.setEditing(Number(channel.id()), message, threadId);
    state.setDraft(Number(channel.id()), message.content() ?? '', threadId);
    m.redraw();
  }

  protected onSent(): void {
    this.pinned = true;
    this.scrollToBottom();
  }

  protected scrollToBottom(): void {
    if (!this.scroller) return;

    this.scroller.scrollTop = this.scroller.scrollHeight;
  }
}
