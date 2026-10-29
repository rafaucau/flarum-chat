import type FlagMessageModal from '../components/FlagMessageModal';
import type FlagPostModal from 'ext:flarum/flags/forum/components/FlagPostModal';
import type { ChatFlagPostModalAttrs } from '../components/ChatFlagPostModal';

/**
 * Loaders dos chunks lazy. Cada `import()` fica sozinho numa linha curta: o
 * autoChunkNameLoader só nomeia e registra imports de uma linha, e um import
 * quebrado pelo formatter vira chunk anônimo que o Flarum não serve.
 */

const importChatUi = () => import('../chatUi');

let chatUi: ReturnType<typeof importChatUi> | null = null;

/** Pede o chunk da interface uma vez só; se falhar, a próxima chamada tenta de novo. */
export function loadChatUi(): ReturnType<typeof importChatUi> {
  chatUi ??= importChatUi().catch((error) => {
    chatUi = null;
    throw error;
  });

  return chatUi;
}

export const loadChannelFormModal = () => import('../components/ChannelFormModal');
export const loadChannelInfoModal = () => import('../components/ChannelInfoModal');
export const loadAddMembersModal = () => import('../components/AddMembersModal');
const importChatFlagModal = () => import('../components/FlagMessageModal');
const importFlagPostModal = () => import('ext:flarum/flags/forum/components/FlagPostModal');
const importChatFlagPostModal = () => import('../components/ChatFlagPostModal');

/**
 * Modal de denúncia: o do flarum/flags quando ativo, para a mensagem ser
 * denunciada como um post; senão o do chat.
 */
export async function loadFlagMessageModal(): Promise<{
  default: typeof FlagMessageModal | typeof FlagPostModal<ChatFlagPostModalAttrs>;
}> {
  if (!('flarum-flags' in (flarum.extensions ?? {}))) {
    return importChatFlagModal();
  }

  const [flags, chat] = await Promise.all([importFlagPostModal(), importChatFlagPostModal()]);

  return { default: chat.default(flags.default) };
}
export const loadMessageTooLongModal = () => import('../components/MessageTooLongModal');
export const loadImageLightbox = () => import('../components/ImageLightbox');
export const loadStickerPicker = () => import('../components/StickerPicker');
