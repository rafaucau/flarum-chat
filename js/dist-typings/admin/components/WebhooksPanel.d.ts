import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
interface WebhookModel {
    id(): string;
    attribute<T = unknown>(name: string): T;
    pushAttributes(attrs: Record<string, unknown>): void;
    save(attrs: Record<string, unknown>): Promise<unknown>;
    delete(): Promise<unknown>;
}
/**
 * Administração dos webhooks de entrada. A API só devolve a chave secreta da
 * URL no create e no rotate, por isso a URL nova fica no estado do componente
 * e é exibida uma única vez.
 */
export default class WebhooksPanel extends Component<ComponentAttrs> {
    private webhooks;
    private channels;
    /** Além do bot, só administradores. */
    private admins;
    private loading;
    /** Por id do webhook; a API não as devolve de novo. */
    private revealed;
    private draftName;
    private draftChannel;
    /** Vazio posta como o bot; senão, id de um admin. */
    private draftAuthor;
    private working;
    oninit(vnode: Mithril.Vnode<ComponentAttrs, this>): void;
    view(): Mithril.Children;
    protected createForm(): Mithril.Children;
    protected authorSelect(value: string, onchange: (value: string) => void): Mithril.Children;
    protected authorOf(webhook: WebhookModel): string;
    protected list(): Mithril.Children;
    protected row(webhook: WebhookModel): Mithril.Children;
    protected load(): Promise<void>;
    protected create(): Promise<void>;
    protected setActive(webhook: WebhookModel, active: boolean): Promise<void>;
    protected setAuthor(webhook: WebhookModel, value: string): Promise<void>;
    protected rotate(webhook: WebhookModel): Promise<void>;
    protected remove(webhook: WebhookModel): Promise<void>;
    protected remember(webhook: WebhookModel): void;
}
export {};
