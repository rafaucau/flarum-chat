/**
 * `simple-emoji-map` não traz tipagem. O formato (unicode → shortcodes) é
 * validado onde é usado, em `forum/utils/emoji.ts`.
 */
declare module 'simple-emoji-map';

/**
 * Modal de denúncia do flarum/flags, sem tipagem e fora das dependências: só
 * resolve em runtime onde a extensão está ativa. Declara apenas os membros que
 * o chat lê ou sobrescreve.
 */
declare module 'ext:flarum/flags/forum/components/FlagPostModal' {
  import FormModal from 'flarum/common/components/FormModal';
  import type { IFormModalAttrs } from 'flarum/common/components/FormModal';
  import type Stream from 'flarum/common/utils/Stream';
  import type Mithril from 'mithril';

  export default class FlagPostModal<CustomAttrs extends IFormModalAttrs = IFormModalAttrs> extends FormModal<CustomAttrs> {
    success: boolean;
    reason: Stream<string>;
    reasonDetail: Stream<string>;

    className(): string;
    content(): Mithril.Children;
  }
}
