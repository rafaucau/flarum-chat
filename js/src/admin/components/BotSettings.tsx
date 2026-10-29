import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Avatar from 'flarum/common/components/Avatar';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';

import { MessagePreview } from './ChatPreview';

/**
 * Quem o chat usa para postar: uma conta real ou o bot. As opções se excluem,
 * então o formulário só mostra a metade que está valendo; editar nome e foto do
 * bot com uma conta escolhida não teria efeito.
 */
export default class BotSettings extends Component<ComponentAttrs> {
  private uploading = false;
  private searching = false;
  private query = '';
  private candidates: User[] = [];
  private searchTimeout?: number;
  private searchSequence = 0;

  view(): Mithril.Children {
    const announcer = this.announcer();

    return (
      <div className="ChatBotSettings">
        <div className="ChatBotSettings-intro helpText">{app.translator.trans('ramon-chat.admin.bot.intro')}</div>

        {announcer ? this.selectedUser(announcer) : this.botForm()}

        {announcer ? null : this.userPicker()}

        {this.preview(announcer)}
      </div>
    );
  }

  protected preview(announcer: User | null): Mithril.Children {
    if (announcer) {
      return <MessagePreview name={announcer.displayName()} avatarUrl={announcer.avatarUrl() ?? null} isUser={true} />;
    }

    const path = this.setting('ramon-chat.bot_avatar_path');
    const url = this.setting('ramon-chat.bot_avatar_url');

    return (
      <MessagePreview
        name={this.setting('ramon-chat.bot_name')}
        avatarUrl={path ? `${app.forum.attribute('assetsBaseUrl')}/${path}` : url || null}
        isUser={false}
      />
    );
  }

  protected botForm(): Mithril.Children {
    const path = this.setting('ramon-chat.bot_avatar_path');
    const url = this.setting('ramon-chat.bot_avatar_url');
    const preview = path ? `${app.forum.attribute('assetsBaseUrl')}/${path}` : url;

    return (
      <div className="ChatBotSettings-bot">
        <div className="ChatBotSettings-avatarRow">
          <div className="ChatBotSettings-preview">
            {preview ? (
              <img src={preview} alt="" />
            ) : (
              <span className="ChatBotSettings-previewEmpty">{(this.setting('ramon-chat.bot_name') || 'B').charAt(0).toUpperCase()}</span>
            )}
          </div>

          <div className="ChatBotSettings-avatarActions">
            {this.uploading ? (
              <LoadingIndicator display="inline" size="small" />
            ) : (
              <>
                <label className="Button Button--primary">
                  {app.translator.trans('ramon-chat.admin.bot.upload')}
                  {/* Hidden rather than styled: a file input cannot be restyled
                      reliably across browsers, so the label is the button. */}
                  <input type="file" accept="image/*" style={{ display: 'none' }} onchange={(e: Event) => this.upload(e)} />
                </label>

                {path ? (
                  <Button className="Button" onclick={() => this.removeUpload()}>
                    {app.translator.trans('ramon-chat.admin.bot.remove_upload')}
                  </Button>
                ) : null}
              </>
            )}
          </div>
        </div>

        <div className="Form-group">
          <label>{app.translator.trans('ramon-chat.admin.settings.bot_name_label')}</label>
          <input
            className="FormControl"
            type="text"
            placeholder="Bot"
            value={this.setting('ramon-chat.bot_name')}
            oninput={(e: Event) => this.set('ramon-chat.bot_name', (e.target as HTMLInputElement).value)}
          />
        </div>

        {/* Only offered when nothing is uploaded. Showing both at once invites the
            question of which one wins, and the answer would only ever be read from
            the source. */}
        {path ? null : (
          <div className="Form-group">
            <label>{app.translator.trans('ramon-chat.admin.settings.bot_avatar_label')}</label>
            <input
              className="FormControl"
              type="url"
              placeholder="https://…/bot.png"
              value={url}
              oninput={(e: Event) => this.set('ramon-chat.bot_avatar_url', (e.target as HTMLInputElement).value)}
            />
          </div>
        )}
      </div>
    );
  }

  protected selectedUser(user: User): Mithril.Children {
    return (
      <div className="ChatBotSettings-selected">
        <Avatar user={user} className="Avatar" />

        <div className="ChatBotSettings-selectedName">
          <strong>{user.displayName()}</strong>
          <div className="helpText">{app.translator.trans('ramon-chat.admin.bot.user_active')}</div>
        </div>

        <Button className="Button" onclick={() => this.clearUser()}>
          {app.translator.trans('ramon-chat.admin.bot.use_bot')}
        </Button>
      </div>
    );
  }

  protected userPicker(): Mithril.Children {
    return (
      <div className="ChatBotSettings-picker">
        <label>{app.translator.trans('ramon-chat.admin.bot.user_label')}</label>
        <div className="helpText">{app.translator.trans('ramon-chat.admin.bot.user_help')}</div>

        <input
          className="FormControl"
          type="search"
          placeholder={app.translator.trans('ramon-chat.admin.bot.user_search', {}, true)}
          value={this.query}
          oninput={(e: Event) => this.search((e.target as HTMLInputElement).value)}
        />

        {this.searching ? <LoadingIndicator display="inline" size="small" /> : null}

        {this.candidates.length > 0 ? (
          <div className="ChatBotSettings-candidates">
            {this.candidates.map((user) => (
              <button type="button" key={user.id()} className="ChatBotSettings-candidate" onclick={() => this.chooseUser(user)}>
                <Avatar user={user} className="Avatar" />
                <span>{user.displayName()}</span>
              </button>
            ))}
          </div>
        ) : null}
      </div>
    );
  }

  protected announcer(): User | null {
    const id = this.setting('ramon-chat.bot_user_id');

    if (!id) return null;

    return app.store.getById<User>('users', String(id)) ?? null;
  }

  protected setting(key: string): string {
    return String(app.data.settings[key] ?? '');
  }

  protected set(key: string, value: string | null): void {
    app.data.settings[key] = value ?? '';

    // Salva na hora, sem o botão da página: o upload e a escolha de conta já
    // gravam direto no servidor, e misturar os dois modelos confunde.
    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/settings`,
        body: { [key]: value },
      })
      .catch(() => {});
  }

  protected async upload(e: Event): Promise<void> {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) return;

    this.uploading = true;
    m.redraw();

    const body = new FormData();
    body.append('ramon-chat-bot', file);

    try {
      const response = await app.request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/chat/bot-avatar`,
        serialize: (raw: any) => raw,
        body,
      });

      // O controller responde com o recurso forum, que já traz o caminho novo.
      this.applyForumPayload(response);
    } catch (err: any) {
      app.alerts.show({ type: 'error' }, err?.response?.errors?.[0]?.detail ?? app.translator.trans('ramon-chat.admin.bot.upload_failed'));
    } finally {
      this.uploading = false;
      // Limpo para escolher o mesmo arquivo de novo ainda disparar change.
      input.value = '';
      m.redraw();
    }
  }

  protected async removeUpload(): Promise<void> {
    this.uploading = true;
    m.redraw();

    try {
      await app.request({
        method: 'DELETE',
        url: `${app.forum.attribute('apiUrl')}/chat/bot-avatar`,
      });

      app.data.settings['ramon-chat.bot_avatar_path'] = '';
    } catch {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-chat.admin.bot.upload_failed'));
    } finally {
      this.uploading = false;
      m.redraw();
    }
  }

  protected search(value: string): void {
    this.query = value;

    window.clearTimeout(this.searchTimeout);

    if (value.trim().length < 2) {
      this.candidates = [];
      this.searching = false;

      return;
    }

    this.searching = true;

    const mine = ++this.searchSequence;

    this.searchTimeout = window.setTimeout(() => {
      app.store
        .find<User[]>('users', {
          filter: { q: value.trim() },
          page: { limit: 5 },
        })
        .then((results) => {
          // Uma requisição anterior mais lenta não pode sobrescrever a mais nova.
          if (mine !== this.searchSequence) return;

          this.candidates = Array.isArray(results) ? results : [];
          this.searching = false;
          m.redraw();
        })
        .catch(() => {
          if (mine !== this.searchSequence) return;

          this.candidates = [];
          this.searching = false;
          m.redraw();
        });
    }, 250);
  }

  protected chooseUser(user: User): void {
    this.set('ramon-chat.bot_user_id', String(user.id()));
    this.query = '';
    this.candidates = [];
    m.redraw();
  }

  protected clearUser(): void {
    this.set('ramon-chat.bot_user_id', '');
    m.redraw();
  }

  // Os atributos `ramon-chat.*` do recurso forum são as settings serializadas,
  // então dá para atualizar a cópia do admin sem recarregar a página.
  protected applyForumPayload(payload: any): void {
    const path = payload?.data?.attributes?.['ramon-chat.botAvatarPath'];

    if (typeof path === 'string') {
      app.data.settings['ramon-chat.bot_avatar_path'] = path;
    }
  }
}
