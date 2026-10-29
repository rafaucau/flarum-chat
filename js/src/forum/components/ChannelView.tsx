import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
import ChatMessage from './ChatMessage';
import ChatComposer from './ChatComposer';
import ChatSelectionBar from './ChatSelectionBar';
import { MessageStreamSkeleton } from './Skeletons';
import { channelIcon } from '../utils/channelIcon';
import { channelActions, openChannelInfo } from '../utils/channelActions';
import { revealAndJump } from '../utils/jumpToMessage';
import { messagePreview } from '../../common/utils/preview';
import iconLabel from '../utils/iconLabel';

export interface ChannelViewAttrs extends ComponentAttrs {
  channel: Channel;
  state: ChatState;
  /** Botão de voltar no cabeçalho, usado só pelo drawer. */
  onBack?: () => void;
  /**
   * Dentro do drawer, que mostra o painel de thread por conta própria. Abrir uma
   * thread só ajusta o state; rotear fecharia o drawer.
   */
  embedded?: boolean;
}

/**
 * Cabeçalho, stream e composer de um canal.
 *
 * O stream é ancorado no fim: segue a mensagem mais nova enquanto o usuário está
 * no fim e mantém a posição quando não está. A ancoragem é explícita, sem
 * depender do navegador.
 */
export default class ChannelView extends Component<ChannelViewAttrs> {
  private scroller: HTMLElement | null = null;

  /** Se o usuário está no fim do stream (ou perto). */
  private pinned = true;

  /** Altura antes de inserir mensagens antigas, para restaurar a posição. */
  private heightBeforePrepend: number | null = null;

  private lastRenderedCount = 0;

  oninit(vnode: Mithril.Vnode<ChannelViewAttrs>): void {
    super.oninit(vnode);

    this.load();
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<ChannelViewAttrs, this>): void {
    // Aqui `this.attrs` ainda é o anterior; o Component só atualiza em
    // super.onbeforeupdate().
    const previousId = this.attrs?.channel?.id();

    super.onbeforeupdate(vnode);

    // Troca de canal na mesma instância.
    if (previousId !== undefined && vnode.attrs.channel.id() !== previousId) {
      this.pinned = true;
      this.lastRenderedCount = 0;
      this.load();
    }
  }

  oncreate(vnode: Mithril.VnodeDOM<ChannelViewAttrs>): void {
    super.oncreate(vnode);

    this.scroller = vnode.dom.querySelector('.ChatChannel-stream');
    this.scrollToBottom();
  }

  onupdate(vnode: Mithril.VnodeDOM<ChannelViewAttrs>): void {
    super.onupdate(vnode);

    const stream = this.attrs.state.stream(Number(this.attrs.channel.id()));
    const count = stream.messages.length;

    // Restaura a posição para o viewport não pular ao chegarem mensagens antigas.
    if (this.heightBeforePrepend !== null && this.scroller) {
      this.scroller.scrollTop = this.scroller.scrollHeight - this.heightBeforePrepend;
      this.heightBeforePrepend = null;
    } else if (count > this.lastRenderedCount && this.pinned) {
      this.scrollToBottom();
    }

    this.lastRenderedCount = count;
  }

  view(): Mithril.Children {
    const { channel, state } = this.attrs;
    const stream = state.stream(Number(channel.id()));

    return (
      <div className="ChatChannel">
        {this.header()}
        {this.pinnedBar()}

        {/* Âncora do botão de descer: em `.ChatChannel` dependeria da altura do
            composer, e no scroller rolaria junto com o conteúdo. */}
        <div className="ChatChannel-streamWrap">
          <div className="ChatChannel-stream" onscroll={(e: Event) => this.onScroll(e)}>
            {stream.loading && stream.messages.length === 0 ? this.skeleton() : null}
            {/* Skeleton em vez de spinner: ocupa a altura das mensagens que chegam. */}
            {stream.hasMore && stream.messages.length > 0 ? (
              <div className="ChatChannel-loadMore">{stream.loading ? MessageStreamSkeleton(2) : null}</div>
            ) : null}

            {stream.loadedInitial && stream.messages.length === 0 ? (
              <div className="ChatBrowse-empty">{app.translator.trans('ramon-chat.forum.channel.no_messages')}</div>
            ) : null}

            {this.rows(stream.messages, stream.dividerAfterId)}
          </div>

          {this.scrollDownButton()}
        </div>

        {this.typingIndicator()}

        {/* A seleção substitui o composer: são modos diferentes. */}
        {state.selecting ? (
          <ChatSelectionBar channel={channel} state={state} />
        ) : (
          <ChatComposer channel={channel} state={state} onSent={() => this.onSent()} />
        )}

        {/* Anuncia novas mensagens ao leitor de tela sem roubar o foco. */}
        <div className="ChatChannel-liveRegion" role="status" aria-live="polite" />
      </div>
    );
  }

  /**
   * Faixa da mensagem fixada sob o cabeçalho, também no drawer. O clique pula
   * para a mensagem, carregando o histórico até ela se preciso; uma fixada que o
   * stream do canal não mostra (resposta de thread) abre o painel de fixadas.
   */
  protected pinnedBar(): Mithril.Children {
    const { channel, state } = this.attrs;
    const pinned = state.latestPinned(Number(channel.id()));

    if (!pinned) return null;

    const text = messagePreview(pinned);
    const count = state.pinnedCount(Number(channel.id()));

    return (
      <div className="ChatChannel-pinnedBar">
        <button
          type="button"
          className="ChatChannel-pinnedBar-jump"
          title={app.translator.trans('ramon-chat.forum.channel.pinned_messages', {}, true)}
          onclick={() => this.jumpToPinned(pinned)}
        >
          <i className="ChatChannel-pinnedBar-icon fas fa-thumbtack" aria-hidden="true" />
          <span className="ChatChannel-pinnedBar-text">{text || app.translator.trans('ramon-chat.forum.message.pinned')}</span>
        </button>

        {/* Só com mais de uma fixada: a faixa já mostra a mais nova. Fica como
            irmão, não dentro do botão da faixa: botão dentro de botão é inválido. */}
        {count > 1 ? (
          <button
            type="button"
            className={classList('ChatChannel-pinnedBar-all', {
              'ChatChannel-pinnedBar-all--active': state.showPinned,
            })}
            title={app.translator.trans('ramon-chat.forum.channel.view_all_pinned', { count }, true)}
            onclick={() => {
              state.togglePinned();
              m.redraw();
            }}
          >
            <span className="ChatChannel-pinnedBar-count">{count}</span>
            <i className="fas fa-angle-right" aria-hidden="true" />
          </button>
        ) : null}
      </div>
    );
  }

  protected jumpToPinned(pinned: Message): void {
    const channelId = Number(this.attrs.channel.id());

    revealAndJump(this.attrs.state, channelId, pinned.id()!, this.scroller).then((found) => {
      if (!found) this.attrs.state.togglePinned();
      m.redraw();
    });
  }

  /**
   * Barra do canal: ícone, nome, descrição, ações. Não aparece com embedded: o
   * cabeçalho do drawer já faz esse papel.
   */
  protected header(): Mithril.Children {
    const { channel, state, onBack, embedded } = this.attrs;

    if (embedded) return null;

    return (
      <div className="ChatChannel-header">
        {onBack ? (
          <Button
            className="Button Button--icon Button--flat"
            icon="fas fa-chevron-left"
            {...iconLabel(app.translator.trans('ramon-chat.forum.sidebar.back_to_channels', {}, true))}
            onclick={onBack}
          />
        ) : null}

        {/* Nome e descrição em linhas separadas: na mesma linha o flex cortava o nome. */}
        <button type="button" className="ChatChannel-title" onclick={() => openChannelInfo(channel)}>
          {channelIcon(channel, 'ChatChannel-icon')}

          <span className="ChatChannel-titleText">
            <span className="ChatChannel-name">{channel.displayName()}</span>

            {channel.description() ? <span className="ChatChannel-description">{channel.description()}</span> : null}
          </span>
        </button>

        {/* Mesmo conjunto do menu do drawer; as permissões ficam em utils/channelActions. */}
        <div className="ChatChannel-headerActions">
          {channelActions(channel, state).map((action) => (
            <Button
              key={action.key}
              className={classList('Button Button--icon Button--flat', {
                'ChatChannel-headerAction--active': action.active,
              })}
              icon={action.icon}
              {...iconLabel(action.label)}
              loading={action.loading}
              onclick={action.onclick}
            />
          ))}
        </div>
      </div>
    );
  }

  /** Intercala separadores de data e o divisor de não lidas com as mensagens. */
  protected rows(messages: Message[], dividerAfterId: number | null): Mithril.Children {
    const out: Mithril.Children[] = [];
    let lastDate: string | null = null;
    let dividerPlaced = false;

    messages.forEach((message, index) => {
      const previous = index > 0 ? messages[index - 1] : null;

      const at = message.createdAt();
      const dateKey = at ? at.toDateString() : null;

      if (dateKey && dateKey !== lastDate) {
        out.push(
          <div className="ChatDateSeparator" key={`date-${dateKey}`}>
            <span className="ChatDateSeparator-label">{this.dateLabel(at!)}</span>
          </div>
        );

        lastDate = dateKey;
      }

      if (!dividerPlaced && dividerAfterId !== null && Number(message.id()) > dividerAfterId) {
        out.push(
          <div className="ChatUnreadDivider" key="unread">
            <span className="ChatUnreadDivider-label">{app.translator.trans('ramon-chat.forum.stream.new_messages')}</span>
          </div>
        );

        dividerPlaced = true;
      }

      out.push(
        <ChatMessage
          key={message.id()}
          message={message}
          previous={previous}
          state={this.attrs.state}
          onReply={(msg: Message) => this.reply(msg)}
          onEdit={(msg: Message) => this.edit(msg)}
          onOpenThread={(msg: Message) => this.openThread(msg)}
        />
      );
    });

    return out;
  }

  protected dateLabel(date: Date): string {
    const today = new Date();
    const yesterday = new Date(today.getTime() - 86400000);

    if (date.toDateString() === today.toDateString()) {
      return app.translator.trans('ramon-chat.forum.stream.today', {}, true);
    }

    if (date.toDateString() === yesterday.toDateString()) {
      return app.translator.trans('ramon-chat.forum.stream.yesterday', {}, true);
    }

    // Day.js, não toLocaleDateString: o locale do navegador não é o do fórum. O
    // formato é uma tradução para o pacote de idioma poder reordenar.
    return app.translator.formatDateTime(dayjs(date), 'ramon-chat.forum.stream.date_format');
  }

  protected typingIndicator(): Mithril.Children {
    const names = this.attrs.state.typistsIn(Number(this.attrs.channel.id()));

    if (names.length === 0) return <div className="ChatTyping" />;

    return (
      <div className="ChatTyping">
        <span className="ChatTyping-dots">
          <span />
          <span />
          <span />
        </span>
        <span>
          {names.length === 1
            ? app.translator.trans('ramon-chat.forum.typing.one', {
                username: names[0],
              })
            : app.translator.trans('ramon-chat.forum.typing.several', {
                count: names.length,
              })}
        </span>
      </div>
    );
  }

  protected skeleton(): Mithril.Children {
    return (
      <div className="ChatSkeleton">
        {[0, 1, 2, 3, 4].map((i) => (
          <div className="ChatSkeleton-row" key={i}>
            <div className="ChatSkeleton-avatar" />
            <div className="ChatSkeleton-lines">
              <div className="ChatSkeleton-line ChatSkeleton-line--short" />
              <div className="ChatSkeleton-line ChatSkeleton-line--long" />
            </div>
          </div>
        ))}
      </div>
    );
  }

  // ── Comportamento ──

  protected async load(): Promise<void> {
    const { channel, state } = this.attrs;
    const channelId = Number(channel.id());

    // Sem await: a faixa de fixada não deve atrasar a primeira página.
    state.loadPinnedPreview(channelId).catch(() => {});

    await state.loadChannel(channelId);

    // Só depois da primeira página, para o divisor usar o marcador anterior.
    state.markRead(channelId);
  }

  protected onScroll(e: Event): void {
    const el = e.target as HTMLElement;
    const wasPinned = this.pinned;

    // 40px de tolerância para arredondamento e última linha parcialmente visível.
    this.pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 40;

    // O Mithril redesenha após cada evento de scroll. Pula o redraw só longe do
    // fim, onde nada muda; no fim ainda redesenha porque `markRead` altera o
    // badge e não chama redraw.
    if (!this.pinned && !wasPinned) {
      (e as Event & { redraw?: boolean }).redraw = false;
    }

    if (el.scrollTop < 120) {
      const stream = this.attrs.state.stream(Number(this.attrs.channel.id()));

      if (stream.hasMore && !stream.loading) {
        this.heightBeforePrepend = el.scrollHeight;
        this.attrs.state.fetchPage(Number(this.attrs.channel.id()));
      }
    }

    if (this.pinned) {
      this.attrs.state.markRead(Number(this.attrs.channel.id()));
    }
  }

  /**
   * Botão de voltar ao fim. Segue `pinned`, que já decide se o stream acompanha as
   * novas mensagens, então aparece exatamente quando não acompanha.
   */
  protected scrollDownButton(): Mithril.Children {
    if (this.pinned) return null;

    const label = app.translator.trans('ramon-chat.forum.channel.scroll_to_latest', {}, true);

    return (
      <button
        type="button"
        className="ChatChannel-scrollDown"
        title={label}
        aria-label={label}
        onclick={() => {
          this.pinned = true;
          this.scrollToBottom();
        }}
      >
        <i className="fas fa-chevron-down" aria-hidden="true" />
      </button>
    );
  }

  /**
   * Pula para a mensagem mais nova, sem animação: o `onupdate` interrompe a
   * animação ao reancorar, e o `scrollTo` suave calcula o alvo uma vez só e para
   * antes do fim se linhas ainda estão sendo montadas.
   */
  protected scrollToBottom(): void {
    if (!this.scroller) return;

    this.scroller.scrollTop = this.scroller.scrollHeight;
  }

  protected onSent(): void {
    this.pinned = true;
    this.scrollToBottom();
  }

  protected reply(message: Message): void {
    const { channel, state } = this.attrs;

    state.setReplyingTo(Number(channel.id()), message);
    m.redraw();
  }

  protected edit(message: Message): void {
    const { channel, state } = this.attrs;

    state.setEditing(Number(channel.id()), message);
    state.setDraft(Number(channel.id()), message.content() ?? '');
    m.redraw();
  }

  protected openThread(message: Message): void {
    const thread = message.thread();
    const { channel, state, embedded } = this.attrs;

    if (thread) {
      state.activeThreadId = Number(thread.id());

      // No drawer o painel abre sobre a conversa; rotear fecharia o drawer.
      if (embedded) {
        m.redraw();

        return;
      }

      m.route.set(app.route('chat.thread', { id: channel.id(), threadId: thread.id() }));

      return;
    }

    // Sem thread ainda: o envio cria uma. Marcada como ramificação, senão o
    // composer não a distingue de uma resposta comum.
    state.setReplyingTo(Number(channel.id()), message, null, true);
    m.redraw();
  }
}
