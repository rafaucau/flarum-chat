import type FlagMessageModal from "../components/FlagMessageModal";
import type FlagPostModal from "ext:flarum/flags/forum/components/FlagPostModal";
import type { ChatFlagPostModalAttrs } from "../components/ChatFlagPostModal";

/**
 * Loaders for the chat's lazy chunks.
 *
 * Each `import()` sits alone on a short line: flarum-webpack-config's
 * autoChunkNameLoader only names and registers a dynamic import written on a
 * single line, and an import broken up by the formatter becomes an anonymous
 * chunk that Flarum cannot serve.
 */

const importChatUi = () => import("../chatUi");

let chatUi: ReturnType<typeof importChatUi> | null = null;

/**
 * Returns the interface chunk, requesting it only once. A failure forgets the
 * promise, so the next attempt retries the request.
 */
export function loadChatUi(): ReturnType<typeof importChatUi> {
  chatUi ??= importChatUi().catch((error) => {
    chatUi = null;
    throw error;
  });

  return chatUi;
}

export const loadChannelFormModal = () =>
  import("../components/ChannelFormModal");
export const loadChannelInfoModal = () =>
  import("../components/ChannelInfoModal");
export const loadAddMembersModal = () =>
  import("../components/AddMembersModal");
const importChatFlagModal = () => import("../components/FlagMessageModal");
const importFlagPostModal = () =>
  import("ext:flarum/flags/forum/components/FlagPostModal");
const importChatFlagPostModal = () => import("../components/ChatFlagPostModal");

/**
 * The report dialog: flarum/flags' own where that extension is enabled, so a
 * message is reported the way a post is, and the chat's otherwise.
 */
export async function loadFlagMessageModal(): Promise<{
  default:
    typeof FlagMessageModal | typeof FlagPostModal<ChatFlagPostModalAttrs>;
}> {
  if (!("flarum-flags" in (flarum.extensions ?? {}))) {
    return importChatFlagModal();
  }

  const [flags, chat] = await Promise.all([
    importFlagPostModal(),
    importChatFlagPostModal(),
  ]);

  return { default: chat.default(flags.default) };
}
export const loadMessageTooLongModal = () =>
  import("../components/MessageTooLongModal");
export const loadImageLightbox = () => import("../components/ImageLightbox");
export const loadStickerPicker = () => import("../components/StickerPicker");
