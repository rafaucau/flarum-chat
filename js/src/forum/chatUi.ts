/**
 * Fachada do chunk lazy com a interface do chat. Um único ponto de entrada para
 * as três superfícies (página, gaveta, navegação de canais) faz o webpack gerar
 * um chunk nomeado e registrado, em vez de chunks numerados que o Flarum não registra.
 */
export { default as ChatPage } from './components/ChatPage';
export { default as ChatDrawerPanel } from './components/ChatDrawerPanel';
export { default as BrowseChannelsPage } from './components/BrowseChannelsPage';
export { default as ChatSidebar } from './components/ChatSidebar';
export { default as ChannelView } from './components/ChannelView';
export { default as ThreadPanel } from './components/ThreadPanel';
export { default as PinnedPanel } from './components/PinnedPanel';
export { default as ThreadsList } from './components/ThreadsList';
export { default as ChatSearch } from './components/ChatSearch';
export { default as ChatMessage } from './components/ChatMessage';
export { default as ChatComposer } from './components/ChatComposer';
export { default as ChatSelectionBar } from './components/ChatSelectionBar';
export { default as ChatAutocomplete } from './components/ChatAutocomplete';
export { default as FlaggedMessagesList } from './components/FlaggedMessagesList';
