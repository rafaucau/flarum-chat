import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Dropdown from 'flarum/common/components/Dropdown';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import type ChatState from '../state/ChatState';

export interface ChatSelectionBarAttrs extends ComponentAttrs {
  channel: Channel;
  state: ChatState;
}

interface TranscriptResponse {
  data: {
    attributes: {
      format: string;
      content: string;
      count: number;
      omitted: number;
    };
  };
}

/**
 * Barra da seleção de mensagens: citar numa discussão, copiar, mover.
 *
 * O servidor monta a transcrição (TranscriptController) e rechecka cada id com
 * `whereVisibleTo`; as descartadas voltam contadas.
 */
export default class ChatSelectionBar extends Component<ChatSelectionBarAttrs> {
  private working = false;

  view(): Mithril.Children {
    const { state } = this.attrs;
    const count = state.selected.size;

    return (
      <div className="ChatSelectionBar">
        <span className="ChatSelectionBar-count">{app.translator.trans('ramon-chat.forum.selection.count', { count })}</span>

        <div className="ChatSelectionBar-actions">
          <Button className="Button Button--text" icon="fas fa-quote-left" disabled={count === 0 || this.working} onclick={() => this.quote()}>
            {app.translator.trans('ramon-chat.forum.message.quote_in_discussion')}
          </Button>

          <Button className="Button Button--text" icon="fas fa-copy" disabled={count === 0 || this.working} onclick={() => this.copy()}>
            {app.translator.trans('ramon-chat.forum.message.copy_text')}
          </Button>

          {this.moveControls(count)}

          <Button className="Button Button--text" icon="fas fa-xmark" onclick={() => this.cancel()}>
            {app.translator.trans('ramon-chat.forum.selection.cancel')}
          </Button>
        </div>
      </div>
    );
  }

  /**
   * Mover exige `ramon-chat.moderate`. Canais fechados ou arquivados são recusados
   * pelo servidor, então não aparecem como destino.
   */
  protected moveControls(count: number): Mithril.Children {
    const { channel, state } = this.attrs;

    if (!app.forum.attribute<boolean>('canModerateChat')) return null;

    const targets = state.channels.filter((candidate) => candidate.id() !== channel.id() && candidate.canPostMessage() && !candidate.isArchived());

    if (targets.length === 0) return null;

    const blocked = count === 0 || this.working;

    // Escolher o canal já move. O menu é posicionado no components.less, não por
    // `menuClassName`: o core recalcula o posicionamento contra a janela a cada
    // abertura, o que erra numa barra presa ao fundo do drawer.
    return (
      <Dropdown
        className="ChatSelectionBar-move"
        buttonClassName="Button Button--text"
        icon="fas fa-right-left"
        label={app.translator.trans('ramon-chat.forum.selection.move_to')}
        buttonAttrs={blocked ? { disabled: 'true' } : {}}
      >
        {targets.map((target) => (
          <Button key={target.id()} icon="fas fa-hashtag" onclick={() => this.move(String(target.id()))}>
            {target.displayName()}
          </Button>
        ))}
      </Dropdown>
    );
  }

  // ── Ações ──

  protected async transcript(format: 'markup' | 'plain'): Promise<TranscriptResponse['data']['attributes'] | null> {
    this.working = true;
    m.redraw();

    try {
      const payload = await app.request<TranscriptResponse>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat/transcript`,
        body: {
          data: {
            attributes: { messageIds: [...this.attrs.state.selected], format },
          },
        },
      });

      const attributes = payload.data.attributes;

      if (attributes.omitted > 0) {
        app.alerts.show(
          { type: 'warning' },
          app.translator.trans('ramon-chat.forum.selection.omitted', {
            count: attributes.omitted,
          })
        );
      }

      return attributes;
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.selection.failed'));

      return null;
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected async quote(): Promise<void> {
    const rendered = await this.transcript('markup');

    if (!rendered) return;

    const DiscussionComposer = (await import('flarum/forum/components/DiscussionComposer')).default;

    // Via `originalContent`: ComposerBody.oninit sobrescreve `fields.content` ao
    // montar. Assim fechar logo em seguida também não pede para descartar.
    await app.composer.load(() => Promise.resolve({ default: DiscussionComposer }), {
      user: app.session.user,
      originalContent: rendered.content,
    });

    await app.composer.show();

    this.cancel();
  }

  protected async copy(): Promise<void> {
    const rendered = await this.transcript('plain');

    if (!rendered) return;

    try {
      await navigator.clipboard.writeText(rendered.content);

      app.alerts.show({ type: 'success' }, app.translator.trans('ramon-chat.forum.selection.copied'));
      this.cancel();
    } catch {
      // O clipboard é negado fora de contexto seguro e em alguns navegadores embutidos.
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.forum.selection.copy_failed'));
    }
  }

  protected async move(target: string): Promise<void> {
    const { state, channel } = this.attrs;
    const ids = [...state.selected];

    this.working = true;
    m.redraw();

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat/messages/move`,
        body: {
          data: {
            attributes: { messageIds: ids, channelId: Number(target) },
          },
        },
      });

      // Saíram do canal; remove da janela sem recarregar a página toda.
      for (const id of ids) {
        state.removeMessage(Number(channel.id()), id);
      }

      app.alerts.show(
        { type: 'success' },
        app.translator.trans('ramon-chat.forum.selection.moved', {
          count: ids.length,
        })
      );

      this.cancel();
    } catch (e: any) {
      app.alerts.show({ type: 'error' }, e?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.forum.selection.failed'));
    } finally {
      this.working = false;
      m.redraw();
    }
  }

  protected cancel(): void {
    const { state } = this.attrs;

    state.selecting = false;
    state.selected.clear();
    m.redraw();
  }
}
