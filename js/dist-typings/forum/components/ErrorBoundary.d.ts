import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
export interface ErrorBoundaryAttrs extends ComponentAttrs {
    /** Aparece no console para indicar qual parte falhou. */
    area?: string;
}
/**
 * Impede que um componente quebrado derrube a página.
 *
 * O Mithril não tem `componentDidCatch`: uma exceção em `view()` aborta o render
 * inteiro e deixa a árvore fora de sincronia com o DOM. Só pega erros de render,
 * não de event handlers nem de callbacks assíncronos.
 */
export default class ErrorBoundary extends Component<ErrorBoundaryAttrs> {
    private failed;
    view(vnode: Mithril.Vnode<ErrorBoundaryAttrs>): Mithril.Children;
}
