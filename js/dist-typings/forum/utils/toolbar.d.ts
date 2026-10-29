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
export declare function mobileTitleControl(title: string): Mithril.Children;
/**
 * Saída de uma seção do chat no celular, no slot direito da toolbar. O esquerdo é
 * do `Navigation` do core, que em `/chat/search` sem histórico mostra o toggle do
 * drawer em vez da seta. `App-primaryControl` é o mesmo slot onde o `IndexSidebar`
 * põe "Iniciar discussão".
 *
 * `fa-xmark`, não chevron: uma seta para a esquerda na borda direita aponta para o
 * lado errado.
 */
export declare function mobileBackControl(onclick: () => void): Mithril.Children;
