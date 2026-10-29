import type FlagMessageModal from '../components/FlagMessageModal';
import type FlagPostModal from 'ext:flarum/flags/forum/components/FlagPostModal';
import type { ChatFlagPostModalAttrs } from '../components/ChatFlagPostModal';
/**
 * Loaders dos chunks lazy. Cada `import()` fica sozinho numa linha curta: o
 * autoChunkNameLoader só nomeia e registra imports de uma linha, e um import
 * quebrado pelo formatter vira chunk anônimo que o Flarum não serve.
 */
declare const importChatUi: () => Promise<typeof import("../chatUi")>;
/** Pede o chunk da interface uma vez só; se falhar, a próxima chamada tenta de novo. */
export declare function loadChatUi(): ReturnType<typeof importChatUi>;
export declare const loadChannelFormModal: () => Promise<typeof import("../components/ChannelFormModal")>;
export declare const loadChannelInfoModal: () => Promise<typeof import("../components/ChannelInfoModal")>;
export declare const loadAddMembersModal: () => Promise<typeof import("../components/AddMembersModal")>;
/**
 * Modal de denúncia: o do flarum/flags quando ativo, para a mensagem ser
 * denunciada como um post; senão o do chat.
 */
export declare function loadFlagMessageModal(): Promise<{
    default: typeof FlagMessageModal | typeof FlagPostModal<ChatFlagPostModalAttrs>;
}>;
export declare const loadMessageTooLongModal: () => Promise<typeof import("../components/MessageTooLongModal")>;
export declare const loadImageLightbox: () => Promise<typeof import("../components/ImageLightbox")>;
export declare const loadStickerPicker: () => Promise<typeof import("../components/StickerPicker")>;
export {};
