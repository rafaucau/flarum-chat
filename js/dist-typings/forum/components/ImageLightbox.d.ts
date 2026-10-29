import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Message from '../../common/models/Message';
import type Upload from '../../common/models/Upload';
export interface ImageLightboxAttrs extends ComponentAttrs {
    /** Todas as imagens da mensagem, para navegar entre elas. */
    uploads: Upload[];
    /** A que foi clicada. */
    index: number;
    message: Message;
    onClose: () => void;
}
/**
 * Visualizador de imagem em tela cheia, aberto ao clicar numa imagem do stream.
 *
 * Não é um `Modal` do Flarum: ele limita a largura e põe barra de título; aqui a
 * imagem ocupa o viewport sobre fundo escuro.
 */
export default class ImageLightbox extends Component<ImageLightboxAttrs> {
    private index;
    private keyListener?;
    oninit(vnode: Mithril.Vnode<ImageLightboxAttrs>): void;
    oncreate(vnode: Mithril.VnodeDOM<ImageLightboxAttrs>): void;
    onremove(): void;
    view(): Mithril.Children;
    protected onKey(e: KeyboardEvent): void;
    /** Dá a volta, para as setas não travarem na primeira ou na última imagem. */
    protected step(by: number): void;
}
