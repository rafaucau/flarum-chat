import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
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
  private failed = false;

  view(vnode: Mithril.Vnode<ErrorBoundaryAttrs>): Mithril.Children {
    if (this.failed) return this.fallback();

    try {
      return this.attrs.render ? this.attrs.render() : vnode.children;
    } catch (e) {
      this.failed = true;

      // Registrado, não engolido, para a causa não sumir.
      console.error(`[ramon-chat] render failed in ${this.attrs.area ?? 'chat'}:`, e);

      return this.fallback();
    }
  }

  protected fallback(): Mithril.Children {
    return (
      <div className="ChatErrorBoundary">
        <i className="fas fa-triangle-exclamation" aria-hidden="true" />
        <span>{app.translator.trans('ramon-chat.forum.error.render')}</span>

        <Button
          className="Button Button--flat"
          icon="fas fa-rotate"
          onclick={() => {
            this.failed = false;
            m.redraw();
          }}
        >
          {app.translator.trans('ramon-chat.forum.error.retry')}
        </Button>
      </div>
    );
  }
}
