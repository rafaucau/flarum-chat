import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import ChatDrawer, { loadDrawerPanel } from './ChatDrawer';
import { rememberOrigin } from '../utils/morph';
import chatState from '../state/chat';
import { chatTitle, chatIcon } from '../utils/branding';
import { shouldUseChatDrawer } from '../utils/surface';

/**
 * Botão do chat no cabeçalho.
 *
 * O markup copia `HeaderDropdown.getButtonContent()` do core (ícone, bubble, label
 * num `.Button.Button--flat`) para funcionar como ícone no desktop e como linha no
 * drawer do celular. É um `<button>` simples porque não há menu.
 */
export default class ChatNavButton<CustomAttrs extends ComponentAttrs = ComponentAttrs> extends Component<CustomAttrs> {
  view(): Mithril.Children {
    const mentions = this.unreadMentions();
    const unread = this.unreadChannels();
    const icon = chatIcon();
    const label = chatTitle();

    // Contagem, como no sino do core. Menções têm prioridade e ganham a classe
    // `new`, com que o core recolore `.HeaderDropdownBubble`.
    const count = mentions > 0 ? mentions : unread;
    const digits = count > 99 ? '99+' : String(count);

    return (
      <button
        type="button"
        className={classList('Button Button--flat ChatNavButton', {
          new: mentions > 0,
          hasIcon: icon !== null,
        })}
        title={label}
        aria-label={this.ariaLabel(mentions, unread > 0, label)}
        onclick={(e: MouseEvent) => this.open(e.currentTarget as HTMLElement)}
        // Navegação no cliente não traz payload de boot; pré-carrega a lista de
        // canais no hover/foco. `loadChannels` roda uma vez por sessão e reaproveita
        // a requisição em andamento.
        onpointerenter={() => this.prefetch()}
        onfocus={() => this.prefetch()}
      >
        {icon ? <Icon name={icon} className="Button-icon" /> : null}

        {/* As duas classes, como o core: `HeaderDropdownBubble` dá cores e o offset no
            celular, `Bubble` tamanho e posição. Não posicione à mão: o core já
            ancora com `left`. */}
        {count > 0 ? (
          <span className="Bubble HeaderDropdownBubble" data-digits={digits.length} aria-hidden="true">
            {digits}
          </span>
        ) : null}

        {/* Sempre emitido, como no core: no drawer do celular o botão vira linha com
            nome. O CSS esconde o label a partir de @tablet-up. */}
        <span className="Button-label">
          <span className="Button-labelText">{label}</span>
        </span>
      </button>
    );
  }

  /**
   * Pré-carrega o que abrir o chat vai pedir: canais, rascunhos e o chunk da
   * interface, onde ficam o drawer e a página.
   */
  protected prefetch(): void {
    loadDrawerPanel().catch(() => {});
    chatState.loadChannels().catch(() => {});
    chatState.loadDrafts().catch(() => {});
  }

  /**
   * Abre o drawer ou a página, conforme a preferência do usuário. Não fecha o
   * drawer do Flarum: o `oninit` de `Page` já chama `app.drawer.hide()`.
   */
  open(from?: HTMLElement): void {
    if (shouldUseChatDrawer()) {
      if (!chatState.drawerOpen) rememberOrigin(from, 'drawer');

      ChatDrawer.open();

      return;
    }

    m.route.set(app.route('chat.index'));
  }

  /**
   * Lido do ChatState, não do usuário: o atributo serializado é um retrato do
   * render, e o state prefere a lista de canais carregada.
   */
  protected unreadChannels(): number {
    return chatState.unreadSummary().messages;
  }

  protected unreadMentions(): number {
    return chatState.unreadSummary().mentions;
  }

  /**
   * O bubble é aria-hidden; o label precisa dizer se são menções ou não lidas,
   * porque a cor que as distingue não é anunciada.
   */
  protected ariaLabel(mentions: number, hasUnread: boolean, label: string): string {
    if (mentions > 0) {
      return app.translator.trans('ramon-chat.forum.nav.unread_mentions', { count: mentions }, true);
    }

    if (hasUnread) {
      return app.translator.trans('ramon-chat.forum.nav.unread_messages', { count: this.unreadChannels() }, true);
    }

    return label;
  }
}
