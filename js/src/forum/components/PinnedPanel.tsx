import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
import ChatMessage from './ChatMessage';
import { MessageStreamSkeleton } from './Skeletons';
import iconLabel from '../utils/iconLabel';

export interface PinnedPanelAttrs extends ComponentAttrs {
  channel: Channel;
  state: ChatState;
  onClose: () => void;
  /**
   * true dentro do drawer, onde os painéis se empilham sem URL. Como no
   * ChannelView: rotear a partir do drawer o fecharia.
   */
  embedded?: boolean;
}

/**
 * Mensagens fixadas de um canal. Usa o mesmo espaço do painel de thread (nunca
 * abrem juntos). Fixar e desafixar ficam na própria linha da mensagem.
 */
export default class PinnedPanel extends Component<PinnedPanelAttrs> {
  private messages: Message[] = [];
  private loading = true;

  oninit(vnode: Mithril.Vnode<PinnedPanelAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<PinnedPanelAttrs, this>): void {
    const previousId = this.attrs?.channel?.id();

    super.onbeforeupdate(vnode);

    if (previousId !== undefined && vnode.attrs.channel.id() !== previousId) {
      this.loading = true;
      this.load();
    }
  }

  view(): Mithril.Children {
    const { state, onClose } = this.attrs;

    return (
      <div className="ChatThreadPanel ChatPinnedPanel">
        <div className="ChatThreadPanel-header">
          <i className="fas fa-thumbtack" aria-hidden="true" />

          <span className="ChatThreadPanel-title">{app.translator.trans('ramon-chat.forum.channel.pinned_messages')}</span>

          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-xmark"
            {...iconLabel(app.translator.trans('ramon-chat.forum.channel.close_pinned', {}, true))}
            onclick={onClose}
          />
        </div>

        <div className="ChatThreadPanel-stream">
          {this.loading ? MessageStreamSkeleton(3) : null}

          {!this.loading && this.messages.length === 0 ? (
            <div className="ChatBrowse-empty">{app.translator.trans('ramon-chat.forum.channel.no_pinned_messages')}</div>
          ) : null}

          {/* `previous` é null em toda linha: as fixadas não são consecutivas, então
              não se agrupam por autor. */}
          {this.messages.map((message) => (
            <ChatMessage
              key={message.id()}
              message={message}
              previous={null}
              state={state}
              hideThreadIndicator
              onReply={(msg: Message) => this.reply(msg)}
              onEdit={(msg: Message) => this.edit(msg)}
            />
          ))}
        </div>
      </div>
    );
  }

  /**
   * Responder a uma fixada. O painel não tem composer: fecha e passa o contexto ao
   * do canal, ou ao da thread quando a mensagem está numa thread.
   */
  protected reply(message: Message): void {
    this.stage(message, (channelId, threadId) => this.attrs.state.setReplyingTo(channelId, message, threadId));
  }

  protected edit(message: Message): void {
    this.stage(message, (channelId, threadId) => {
      this.attrs.state.setEditing(channelId, message, threadId);
      this.attrs.state.setDraft(channelId, message.content() ?? '', threadId);
    });
  }

  /** Fecha o painel, abre o composer certo e aplica a ação nele. */
  protected stage(message: Message, apply: (channelId: number, threadId: number | null) => void): void {
    const state = this.attrs.state;
    const channelId = Number(this.attrs.channel.id());
    const threadId = message.threadId() ?? null;

    state.showPinned = false;

    apply(channelId, threadId);

    this.attrs.onClose();

    // Resposta de thread: o painel da thread precisa abrir, senão o composer com o
    // contexto não é o que está na tela.
    if (threadId !== null) {
      state.activeThreadId = threadId;

      // Na página a thread vem da URL, que o ChatPage relê a cada update; no
      // drawer não há rota.
      if (!this.attrs.embedded) {
        m.route.set(app.route('chat.thread', { id: channelId, threadId }));

        return;
      }
    }

    m.redraw();
  }

  protected async load(): Promise<void> {
    try {
      const results = (await app.store.find('chat-messages', {
        filter: {
          channel: Number(this.attrs.channel.id()),
          pinned: true,
          // Sem isso o filtro de canal omite respostas de thread fixadas.
          includeThreadReplies: true,
        },
        sort: '-pinnedAt',
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
}
