/**
 * `simple-emoji-map` ships no typings. The same shim flarum/emoji uses.
 *
 * The module's shape (unicode → shortcode names) is asserted at the point of use
 * in `forum/utils/emoji.ts`, which also guards against a malformed entry.
 */
declare module "simple-emoji-map";

/**
 * flarum/flags' report dialog, which ships no typings and is not a dependency
 * of the chat: the module resolves at runtime only where that extension is
 * enabled. The members the chat reads or overrides, as the class defines them.
 */
declare module "ext:flarum/flags/forum/components/FlagPostModal" {
  import FormModal from "flarum/common/components/FormModal";
  import type { IFormModalAttrs } from "flarum/common/components/FormModal";
  import type Stream from "flarum/common/utils/Stream";
  import type Mithril from "mithril";

  export default class FlagPostModal<
    CustomAttrs extends IFormModalAttrs = IFormModalAttrs,
  > extends FormModal<CustomAttrs> {
    success: boolean;
    reason: Stream<string>;
    reasonDetail: Stream<string>;

    className(): string;
    content(): Mithril.Children;
  }
}
