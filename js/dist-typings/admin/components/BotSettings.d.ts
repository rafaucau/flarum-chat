import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
/**
 * Quem o chat usa para postar: uma conta real ou o bot. As opções se excluem,
 * então o formulário só mostra a metade que está valendo; editar nome e foto do
 * bot com uma conta escolhida não teria efeito.
 */
export default class BotSettings extends Component<ComponentAttrs> {
    private uploading;
    private searching;
    private query;
    private candidates;
    private searchTimeout?;
    private searchSequence;
    view(): Mithril.Children;
    protected preview(announcer: User | null): Mithril.Children;
    protected botForm(): Mithril.Children;
    protected selectedUser(user: User): Mithril.Children;
    protected userPicker(): Mithril.Children;
    protected announcer(): User | null;
    protected setting(key: string): string;
    protected set(key: string, value: string | null): void;
    protected upload(e: Event): Promise<void>;
    protected removeUpload(): Promise<void>;
    protected search(value: string): void;
    protected chooseUser(user: User): void;
    protected clearUser(): void;
    protected applyForumPayload(payload: any): void;
}
