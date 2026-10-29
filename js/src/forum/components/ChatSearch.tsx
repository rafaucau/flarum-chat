import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Avatar from 'flarum/common/components/Avatar';
import classList from 'flarum/common/utils/classList';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';

import type Message from '../../common/models/Message';
import type ChatState from '../state/ChatState';
import { SearchResultsSkeleton } from './Skeletons';
import { messagePreview } from '../../common/utils/preview';
import { authorLink } from '../utils/bot';
import iconLabel from '../utils/iconLabel';

export interface ChatSearchAttrs extends ComponentAttrs {
  state: ChatState;
  /** Se definido, restringe a busca a um canal. */
  channelId?: number | null;
  /**
   * Desenhado sobre a conversa no drawer, que não tem rota para `chat.search`.
   * Ganha cabeçalho de volta e abrir um resultado troca o canal no lugar.
   */
  embedded?: boolean;
  /** Fecha o painel. Obrigatório com embedded; ignorado sem. */
  onClose?: () => void;
}

/** Espera após a digitação antes de enviar a requisição (ms). */
const DEBOUNCE = 300;

const PAGE_SIZE = 30;

/**
 * Busca de mensagens em um canal ou em tudo que o usuário pode ler. Usa
 * `filter[q]`, que já aplica o mesmo escopo de visibilidade do stream.
 */
export default class ChatSearch extends Component<ChatSearchAttrs> {
  private query = '';
  private results: Message[] = [];
  private searching = false;
  /** Distingue "sem resultados" de "nada digitado". */
  private searched = false;
  private timer: number | null = null;
  /** Impede que uma requisição antiga e lenta sobrescreva uma mais nova. */
  private sequence = 0;

  onremove(): void {
    if (this.timer !== null) window.clearTimeout(this.timer);
  }

  view(): Mithril.Children {
    const channel = this.attrs.channelId ? this.attrs.state.channel(this.attrs.channelId) : null;

    return (
      <div
        className={classList('ChatSearch', {
          'ChatSearch--embedded': this.attrs.embedded,
        })}
      >
        {/* Mesma casca dos painéis que dividem o slot do drawer. */}
        {this.attrs.embedded ? (
          <div className="ChatThreadPanel-header">
            <i className="fas fa-magnifying-glass" aria-hidden="true" />

            <span className="ChatThreadPanel-title">{app.translator.trans('ramon-chat.forum.search.title')}</span>

            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-xmark"
              {...iconLabel(app.translator.trans('ramon-chat.forum.search.close', {}, true))}
              onclick={this.attrs.onClose}
            />
          </div>
        ) : null}

        <div className="ChatSearch-bar">
          <i className="ChatSearch-icon fas fa-magnifying-glass" aria-hidden="true" />

          <input
            className="ChatSearch-input"
            type="search"
            value={this.query}
            placeholder={app.translator.trans('ramon-chat.forum.search.placeholder', {}, true)}
            aria-label={app.translator.trans('ramon-chat.forum.search.title', {}, true)}
            oninput={(e: Event) => this.onInput((e.target as HTMLInputElement).value)}
            onkeydown={(e: KeyboardEvent) => {
              // Enter busca na hora, sem esperar o debounce.
              if (e.key === 'Enter') this.run();
            }}
          />

          {this.query ? (
            <Button
              className="Button Button--icon Button--flat ChatSearch-clear"
              icon="fas fa-xmark"
              {...iconLabel(app.translator.trans('ramon-chat.forum.search.clear', {}, true))}
              onclick={() => this.clear()}
            />
          ) : null}
        </div>

        {channel ? (
          <div className="ChatSearch-scope">
            {app.translator.trans('ramon-chat.forum.search.in_channel', {
              channel: channel.displayName(),
            })}
          </div>
        ) : null}

        {this.body()}
      </div>
    );
  }

  protected body(): Mithril.Children {
    if (this.searching && this.results.length === 0) {
      return SearchResultsSkeleton();
    }

    if (!this.searched) {
      return <div className="ChatBrowse-empty">{app.translator.trans('ramon-chat.forum.search.prompt')}</div>;
    }

    if (this.results.length === 0) {
      return <div className="ChatBrowse-empty">{app.translator.trans('ramon-chat.forum.search.empty')}</div>;
    }

    return <div className="ChatSearch-results">{this.results.map((message) => this.result(message))}</div>;
  }

  protected result(message: Message): Mithril.Children {
    const channel = this.attrs.state.channel(message.channelId());
    const at = message.createdAt();

    return (
      <button type="button" className="ChatSearch-result" key={message.id()} onclick={() => this.open(message)}>
        <Avatar user={message.user()} className="Avatar" />

        <div className="ChatSearch-result-body">
          <div className="ChatSearch-result-meta">
            <span className="ChatSearch-result-author">{authorLink(message)}</span>
            {channel ? <span className="ChatSearch-result-channel">{channel.displayName()}</span> : null}
            {at ? <span className="ChatSearch-result-time">{humanTime(at)}</span> : null}
          </div>

          {/* Texto puro: o resultado é um trecho de uma linha. */}
          <div className="ChatSearch-result-excerpt">{messagePreview(message)}</div>
        </div>
      </button>
    );
  }

  // ── Comportamento ──

  protected onInput(value: string): void {
    this.query = value;

    if (this.timer !== null) window.clearTimeout(this.timer);

    if (value.trim() === '') {
      this.results = [];
      this.searched = false;

      return;
    }

    this.timer = window.setTimeout(() => this.run(), DEBOUNCE);
  }

  protected async run(): Promise<void> {
    if (this.timer !== null) {
      window.clearTimeout(this.timer);
      this.timer = null;
    }

    const term = this.query.trim();

    if (term === '') return;

    const mine = ++this.sequence;

    this.searching = true;
    m.redraw();

    try {
      const results = (await app.store.find('chat-messages', {
        filter: {
          q: term,
          ...(this.attrs.channelId ? { channel: this.attrs.channelId } : {}),
        },
        sort: '-id',
        page: { limit: PAGE_SIZE },
      })) as unknown as Message[];

      if (mine !== this.sequence) return;

      this.results = Array.isArray(results) ? results : [];
      this.searched = true;
    } catch {
      if (mine !== this.sequence) return;

      this.results = [];
      this.searched = true;
    } finally {
      if (mine === this.sequence) this.searching = false;

      m.redraw();
    }
  }

  protected clear(): void {
    this.query = '';
    this.results = [];
    this.searched = false;

    if (this.timer !== null) window.clearTimeout(this.timer);

    m.redraw();
  }

  /**
   * Abre o canal do resultado, sem pular para a mensagem: o stream pagina a partir
   * da mais nova, e chegar numa antiga exigiria carregar tudo no meio.
   */
  protected open(message: Message): void {
    const channelId = message.channelId();

    if (!channelId) return;

    this.attrs.state.setActiveChannel(channelId);

    // No drawer, rotear fecharia o drawer; fechar o painel já mostra o canal escolhido.
    if (this.attrs.embedded) {
      this.attrs.onClose?.();
      m.redraw();

      return;
    }

    m.route.set(app.route('chat.channel', { id: channelId }));
  }
}
