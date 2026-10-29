import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
/**
 * Botão do chat no cabeçalho.
 *
 * O markup copia `HeaderDropdown.getButtonContent()` do core (ícone, bubble, label
 * num `.Button.Button--flat`) para funcionar como ícone no desktop e como linha no
 * drawer do celular. É um `<button>` simples porque não há menu.
 */
export default class ChatNavButton<CustomAttrs extends ComponentAttrs = ComponentAttrs> extends Component<CustomAttrs> {
    view(): Mithril.Children;
    /**
     * Pré-carrega o que abrir o chat vai pedir: canais, rascunhos e o chunk da
     * interface, onde ficam o drawer e a página.
     */
    protected prefetch(): void;
    /**
     * Abre o drawer ou a página, conforme a preferência do usuário. Não fecha o
     * drawer do Flarum: o `oninit` de `Page` já chama `app.drawer.hide()`.
     */
    open(from?: HTMLElement): void;
    /**
     * Lido do ChatState, não do usuário: o atributo serializado é um retrato do
     * render, e o state prefere a lista de canais carregada.
     */
    protected unreadChannels(): number;
    protected unreadMentions(): number;
    /**
     * O bubble é aria-hidden; o label precisa dizer se são menções ou não lidas,
     * porque a cor que as distingue não é anunciada.
     */
    protected ariaLabel(mentions: number, hasUnread: boolean, label: string): string;
}
