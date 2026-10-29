import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';
/** "local" ou "fof-upload". */
export declare const STORAGE_SETTING = "ramon-chat.upload_storage";
/** Vazio segue o mapeamento por mime do próprio fof/upload. */
export declare const ADAPTER_SETTING = "ramon-chat.fof_upload_adapter";
type GroupKey = 'general' | 'messages' | 'delivery' | 'integrations';
/**
 * `keys` são as settings registradas em extend.tsx, na ordem em que aparecem.
 * Tipo, label e help continuam vindo do registry.
 */
interface Section {
    key: string;
    group: GroupKey;
    icon: string;
    keys: readonly string[];
    columns?: 1 | 2;
    /** Chave de tradução exibida como aviso. */
    warning?: string;
}
interface Card {
    key: string;
    group: GroupKey;
    icon: string;
    /** Em minúsculas, para a busca. */
    haystack: string;
    body: () => Mithril.Children;
}
type SettingEntry = Parameters<ExtensionPage['buildSettingComponent']>[0] extends infer E ? Exclude<E, (...args: any[]) => any> : never;
/**
 * Página de admin do chat, no layout do tema Avocado: abas, cards e busca entre
 * abas. Ao contrário do Avocado, as settings são salvas juntas pela barra do
 * rodapé. Settings registradas e não listadas em SECTIONS caem num card final,
 * para nada sumir do admin.
 */
export default class ChatSettingsPage extends ExtensionPage {
    content(vnode: Mithril.VnodeDOM<any, any>): JSX.Element;
    protected cards(entries: SettingEntry[]): Card[];
    protected haystack(key: string, fields: SettingEntry[], extra?: string): string;
    protected sectionText(key: string, part: 'title' | 'help'): string;
    protected toolbar(cards: Card[], searching: boolean): Mithril.Children;
    protected selectGroup(key: GroupKey): void;
    protected onTabKey(e: KeyboardEvent): void;
    protected sectionBody(section: Section, fields: SettingEntry[]): Mithril.Children;
    protected isOn(raw: unknown, fallback: boolean): boolean;
    protected fieldShown(key: string): boolean;
    protected storesOnFofLocal(): boolean;
    protected webhooksBody(entry: SettingEntry | undefined): Mithril.Children;
    protected card(card: Card, group: GroupKey | null): Mithril.Children;
}
export {};
