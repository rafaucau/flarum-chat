import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
export interface ErrorBoundaryAttrs extends ComponentAttrs {
    /** Aparece no console para indicar qual parte falhou. */
    area?: string;
    /**
     * Monta o conteúdo dentro do boundary. Filhos já chegam prontos, então um erro
     * ao montá-los acontece no `view()` do pai, antes de o boundary rodar.
     */
    render?: () => Mithril.Children;
}
/**
 * Impede que um componente quebrado derrube a página.
 *
 * O Mithril não tem `componentDidCatch`: uma exceção em `view()` aborta o render
 * inteiro e deixa a árvore fora de sincronia com o DOM. Por isso o conteúdo vem
 * em `render`, chamado aqui dentro do `try`. Só pega erros ao montar a árvore:
 * o `view()` de um componente filho roda depois, fora do alcance, assim como
 * event handlers e callbacks assíncronos.
 */
export default class ErrorBoundary extends Component<ErrorBoundaryAttrs> {
    private failed;
    view(vnode: Mithril.Vnode<ErrorBoundaryAttrs>): Mithril.Children;
    protected fallback(): Mithril.Children;
}
