import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import chatState from '../state/chat';
import { loadChatUi } from '../utils/lazy';
import { isNarrowViewport } from '../utils/surface';
import { morphIn } from '../utils/morph';

type Panel = Awaited<ReturnType<typeof loadChatUi>>['ChatDrawerPanel'];

let panel: Panel | null = null;
let pending: Promise<void> | null = null;

/**
 * Baixa o painel do drawer, que fica no chunk lazy da interface. Chamadas
 * simultâneas viram uma só.
 */
export function loadDrawerPanel(): Promise<void> {
  pending ??= loadChatUi().then(
    (ui) => {
      panel = ui.ChatDrawerPanel;
      m.redraw();
    },
    (error) => {
      pending = null;
      throw error;
    }
  );

  return pending;
}

/**
 * Casca do drawer que fica no bundle inicial. Fechado não desenha nada; aberto
 * antes do chunk chegar, mostra a caixa com spinner até o ChatDrawerPanel assumir.
 */
export default class ChatDrawer extends Component<ComponentAttrs> {
  /** Se a caixa provisória estava na tela no último render. */
  private placeholderShown = false;

  oncreate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void {
    super.oncreate(vnode);

    this.morphPlaceholder(vnode.dom);
  }

  onupdate(vnode: Mithril.VnodeDOM<ComponentAttrs, this>): void {
    super.onupdate(vnode);

    this.morphPlaceholder(vnode.dom);
  }

  /**
   * Sem o chunk, é a caixa provisória que cresce a partir do botão; o painel que a
   * substitui entra sem repetir a animação.
   */
  protected morphPlaceholder(dom: Element | null): void {
    const box = dom instanceof HTMLElement && dom.classList.contains('ChatDrawer--loading') ? dom : null;

    if (box && !this.placeholderShown) morphIn(box, 'drawer');

    this.placeholderShown = box !== null;
  }

  view(): Mithril.Children {
    if (!chatState.drawerOpen) return null;

    if (panel) {
      const Panel = panel;

      return <Panel />;
    }

    loadDrawerPanel().catch(() => {});

    if (isNarrowViewport()) return null;

    return (
      <div
        className={classList('ChatDrawer ChatDrawer--loading', {
          'ChatDrawer--collapsed': chatState.drawerCollapsed,
        })}
        role="complementary"
        aria-busy="true"
      >
        <LoadingIndicator />
      </div>
    );
  }

  /**
   * Abre o drawer e carrega a lista de canais na primeira vez. O redraw vem antes
   * do carregamento para o drawer não esperar a ida e volta; a sidebar mostra o
   * skeleton enquanto isso.
   */
  static async open(): Promise<void> {
    chatState.setDrawerOpen(true);
    chatState.setDrawerCollapsed(false);

    loadDrawerPanel().catch(() => {});

    m.redraw();

    if (chatState.channelsLoaded) return;

    await Promise.all([chatState.loadChannels(), chatState.loadDrafts()]).catch(() => {});
  }
}
