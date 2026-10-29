import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

/**
 * Nome da página na toolbar de celular do Flarum. O core posiciona de forma
 * absoluta o que tiver `App-backControl` (esquerda), `App-titleControl` (centro) e
 * `App-primaryControl` (direita), de qualquer lugar da página (`less/common/App.less`).
 * O flarum/messages herda isso do `IndexSidebar`; a sidebar do chat não é um, então
 * ninguém ocupava o slot.
 *
 * Texto em vez do dropdown do core: as seções já estão a um toque pela seta de
 * voltar. Mostra o nome da seção; o canal já se nomeia em `ChatChannel-header`.
 */
export function mobileTitleControl(title: string): Mithril.Children {
  // `span`, não heading: `BrowseChannelsPage` já tem `h1`, e leitores de tela leem o
  // nome em `document.title`, definido por `app.setTitle`.
  return <span className="App-titleControl App-titleControl--text ChatToolbarTitle">{title}</span>;
}

/**
 * Saída de uma seção do chat no celular, no slot direito da toolbar. O esquerdo é
 * do `Navigation` do core, que em `/chat/search` sem histórico mostra o toggle do
 * drawer em vez da seta. `App-primaryControl` é o mesmo slot onde o `IndexSidebar`
 * põe "Iniciar discussão".
 *
 * `fa-xmark`, não chevron: uma seta para a esquerda na borda direita aponta para o
 * lado errado.
 */
export function mobileBackControl(onclick: () => void): Mithril.Children {
  const label = app.translator.trans('ramon-chat.forum.sidebar.back_to_channels', {}, true);

  // Wrapper porque o core estiliza `.App-primaryControl > .Button`; a classe precisa
  // estar no pai para o botão ficar igual ao toggle do lado oposto.
  return (
    <div className="App-primaryControl ChatToolbarBack">
      <Button className="Button Button--icon" icon="fas fa-xmark" title={label} aria-label={label} onclick={onclick} />
    </div>
  );
}
