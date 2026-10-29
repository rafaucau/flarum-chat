import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';

import type Message from '../../common/models/Message';
import type Upload from '../../common/models/Upload';
import { safeFileUrl } from '../utils/url';
import { authorName } from '../utils/bot';
import iconLabel from '../utils/iconLabel';

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
  private index = 0;
  private keyListener?: (e: KeyboardEvent) => void;

  oninit(vnode: Mithril.Vnode<ImageLightboxAttrs>): void {
    super.oninit(vnode);

    this.index = this.attrs.index;
  }

  oncreate(vnode: Mithril.VnodeDOM<ImageLightboxAttrs>): void {
    super.oncreate(vnode);

    this.keyListener = (e: KeyboardEvent) => this.onKey(e);
    document.addEventListener('keydown', this.keyListener);

    // Sem isso, rolar sobre a imagem rola a conversa por trás.
    document.body.style.overflow = 'hidden';

    (vnode.dom as HTMLElement).focus();
  }

  onremove(): void {
    if (this.keyListener) document.removeEventListener('keydown', this.keyListener);

    document.body.style.overflow = '';
  }

  view(): Mithril.Children {
    const { uploads, message, onClose } = this.attrs;
    const upload = uploads[this.index];

    if (!upload) return null;

    const at = message.createdAt();

    return (
      <div
        className="ChatLightbox"
        tabindex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={upload.fileName() ?? ''}
        onclick={(e: Event) => {
          // Só o fundo fecha; clicar na imagem não.
          if (e.target === e.currentTarget) onClose();
        }}
      >
        <div className="ChatLightbox-bar">
          <div className="ChatLightbox-meta">
            <span className="ChatLightbox-author">{authorName(message)}</span>
            {at ? <span className="ChatLightbox-time">{humanTime(at)}</span> : null}
            <span className="ChatLightbox-name">{upload.fileName()}</span>
          </div>

          <div className="ChatLightbox-actions">
            {uploads.length > 1 ? (
              <span className="ChatLightbox-count">
                {this.index + 1} / {uploads.length}
              </span>
            ) : null}

            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-arrow-up-right-from-square"
              {...iconLabel(app.translator.trans('ramon-chat.forum.lightbox.open_original', {}, true))}
              onclick={() => window.open(safeFileUrl(upload.url()), '_blank', 'noopener,noreferrer')}
            />

            <Button
              className="Button Button--icon Button--flat"
              icon="fas fa-xmark"
              {...iconLabel(app.translator.trans('ramon-chat.forum.lightbox.close', {}, true))}
              onclick={onClose}
            />
          </div>
        </div>

        {uploads.length > 1 ? (
          <Button
            className="Button Button--icon Button--flat ChatLightbox-nav ChatLightbox-nav--prev"
            icon="fas fa-chevron-left"
            {...iconLabel(app.translator.trans('ramon-chat.forum.lightbox.previous', {}, true))}
            onclick={(e: Event) => {
              e.stopPropagation();
              this.step(-1);
            }}
          />
        ) : null}

        <img
          className="ChatLightbox-image"
          src={safeFileUrl(upload.url())}
          alt={upload.fileName() ?? ''}
          onclick={(e: Event) => e.stopPropagation()}
        />

        {uploads.length > 1 ? (
          <Button
            className="Button Button--icon Button--flat ChatLightbox-nav ChatLightbox-nav--next"
            icon="fas fa-chevron-right"
            {...iconLabel(app.translator.trans('ramon-chat.forum.lightbox.next', {}, true))}
            onclick={(e: Event) => {
              e.stopPropagation();
              this.step(1);
            }}
          />
        ) : null}
      </div>
    );
  }

  protected onKey(e: KeyboardEvent): void {
    if (e.key === 'Escape') {
      this.attrs.onClose();

      return;
    }

    if (e.key === 'ArrowLeft') this.step(-1);
    if (e.key === 'ArrowRight') this.step(1);
  }

  /** Dá a volta, para as setas não travarem na primeira ou na última imagem. */
  protected step(by: number): void {
    const total = this.attrs.uploads.length;

    if (total < 2) return;

    this.index = (this.index + by + total) % total;
    m.redraw();
  }
}
