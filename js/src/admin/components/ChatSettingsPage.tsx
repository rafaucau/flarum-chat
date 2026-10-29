import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Form from 'flarum/common/components/Form';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

import BotSettings from './BotSettings';
import { BrandingPreview } from './ChatPreview';
import WebhooksPanel from './WebhooksPanel';

/** "local" ou "fof-upload". */
export const STORAGE_SETTING = 'ramon-chat.upload_storage';

/** Vazio segue o mapeamento por mime do próprio fof/upload. */
export const ADAPTER_SETTING = 'ramon-chat.fof_upload_adapter';

/** Só oferecido com um worker contínuo. */
const QUEUE_SETTING = 'ramon-chat.queue_realtime';

/**
 * "sync", "database" ou "other". Nos dois primeiros o servidor entrega os
 * pushes inline de qualquer jeito, então o switch dá lugar a um aviso.
 */
function queueKind(): string {
  const kind = app.data.ramonChatQueueKind;

  return typeof kind === 'string' ? kind : 'sync';
}

const WEBHOOKS_SWITCH = 'ramon-chat.webhooks_enabled';

type GroupKey = 'general' | 'messages' | 'delivery' | 'integrations';

const GROUPS: readonly { key: GroupKey; icon: string }[] = [
  { key: 'general', icon: 'fas fa-comments' },
  { key: 'messages', icon: 'fas fa-message' },
  { key: 'delivery', icon: 'fas fa-tower-broadcast' },
  { key: 'integrations', icon: 'fas fa-plug' },
];

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

const SECTIONS: readonly Section[] = [
  {
    key: 'channels',
    group: 'general',
    icon: 'fas fa-people-group',
    keys: ['ramon-chat.channel_ownership', 'ramon-chat.allow_archiving_channels', 'ramon-chat.threading_default'],
  },
  {
    key: 'appearance',
    group: 'general',
    icon: 'fas fa-palette',
    keys: ['ramon-chat.title', 'ramon-chat.icon', 'ramon-chat.show_icon'],
    columns: 2,
  },
  {
    key: 'messages',
    group: 'messages',
    icon: 'fas fa-message',
    keys: [
      'ramon-chat.max_message_length',
      'ramon-chat.min_message_length',
      'ramon-chat.max_messages_per_second',
      'ramon-chat.message_edit_window_minutes',
    ],
    columns: 2,
  },
  {
    key: 'uploads',
    group: 'messages',
    icon: 'fas fa-paperclip',
    // As duas últimas só existem com o fof/upload ligado; chave não registrada
    // não é desenhada.
    keys: ['ramon-chat.allow_uploads', 'ramon-chat.max_upload_size', STORAGE_SETTING, ADAPTER_SETTING],
    columns: 2,
  },
  {
    key: 'realtime',
    group: 'delivery',
    icon: 'fas fa-bolt',
    keys: [QUEUE_SETTING, 'ramon-chat.notification_sound'],
  },
  {
    key: 'retention',
    group: 'delivery',
    icon: 'fas fa-broom',
    keys: ['ramon-chat.channel_retention_days', 'ramon-chat.dm_retention_days'],
    columns: 2,
    warning: 'ramon-chat.admin.settings.retention_warning',
  },
];

interface Card {
  key: string;
  group: GroupKey;
  icon: string;
  /** Em minúsculas, para a busca. */
  haystack: string;
  body: () => Mithril.Children;
}

type SettingEntry = Parameters<ExtensionPage['buildSettingComponent']>[0] extends infer E ? Exclude<E, (...args: any[]) => any> : never;

const TAB_STORAGE_KEY = 'ramon-chat.admin.tab';

// Lembrada entre visitas: um save que recarrega a página ou a volta da página
// de permissões não deve cair de novo na primeira aba.
let activeGroup: GroupKey = (() => {
  try {
    const saved = localStorage.getItem(TAB_STORAGE_KEY) as GroupKey | null;

    if (saved && GROUPS.some((group) => group.key === saved)) return saved;
  } catch {
    return 'general';
  }

  return 'general';
})();

let query = '';

/**
 * Página de admin do chat, no layout do tema Avocado: abas, cards e busca entre
 * abas. Ao contrário do Avocado, as settings são salvas juntas pela barra do
 * rodapé. Settings registradas e não listadas em SECTIONS caem num card final,
 * para nada sumir do admin.
 */
export default class ChatSettingsPage extends ExtensionPage {
  content(vnode: Mithril.VnodeDOM<any, any>) {
    const entries = (app.registry.getSettings(this.extension.id) ?? []).filter((entry): entry is SettingEntry => typeof entry !== 'function');

    const cards = this.cards(entries);
    const needle = query.trim().toLowerCase();
    const visible = needle ? cards.filter((card) => card.haystack.includes(needle)) : cards.filter((card) => card.group === activeGroup);

    const changes = this.isChanged();

    return (
      <div className="ExtensionPage-settings ChatAdmin">
        <div className="container">
          <Form>
            {this.toolbar(cards, needle !== '')}

            {visible.length > 0 ? (
              <div
                className="ChatAdmin-grid"
                id="ChatAdmin-panel"
                role="tabpanel"
                aria-labelledby={needle ? undefined : `ChatAdmin-tab-${activeGroup}`}
              >
                {visible.map((card) => this.card(card, needle !== '' ? card.group : null))}
              </div>
            ) : (
              <p className="ChatAdmin-empty" role="status">
                <i className="fas fa-comment-slash" aria-hidden="true" />
                {app.translator.trans('ramon-chat.admin.layout.search_empty')}
              </p>
            )}

            <div className={'Form-group Form-controls ChatAdmin-controls' + (changes ? ' is-dirty' : '')}>
              <span className="ChatAdmin-status" role="status">
                <span className="ChatAdmin-statusDot" aria-hidden="true" />
                {changes
                  ? app.translator.trans('ramon-chat.admin.layout.unsaved', {
                      count: changes,
                    })
                  : app.translator.trans('ramon-chat.admin.layout.saved')}
              </span>

              <span className="ChatAdmin-actions">
                {this.resetButton(
                  entries.map((entry) => ({
                    key: entry.setting,
                    label: entry.label,
                  })),
                  app.translator.trans(
                    'core.admin.extension.reset_settings.title_extension',
                    {
                      extensionTitle: this.extension.extra['flarum-extension'].title,
                    },
                    true
                  ),
                  this.extension.id
                )}
                {this.submitButton()}
              </span>
            </div>
          </Form>
        </div>
      </div>
    );
  }

  // Montados a cada draw porque os campos seguem o estado do formulário.
  protected cards(entries: SettingEntry[]): Card[] {
    const byKey = new Map(entries.map((entry) => [entry.setting, entry]));
    const placed = new Set([...SECTIONS.flatMap((section) => section.keys), WEBHOOKS_SWITCH]);
    const leftover = entries.filter((entry) => !placed.has(entry.setting));

    const cards: Card[] = [];

    for (const section of SECTIONS) {
      const fields = section.keys
        .filter((key) => this.fieldShown(key))
        .map((key) => byKey.get(key))
        .filter((entry): entry is SettingEntry => Boolean(entry));

      if (fields.length === 0) continue;

      cards.push({
        key: section.key,
        group: section.group,
        icon: section.icon,
        haystack: this.haystack(section.key, fields),
        body: () => this.sectionBody(section, fields),
      });
    }

    cards.push({
      key: 'announcer',
      group: 'integrations',
      icon: 'fas fa-robot',
      haystack: this.haystack('announcer', [], 'bot avatar'),
      body: () => <BotSettings />,
    });

    const webhooksEntry = byKey.get(WEBHOOKS_SWITCH);

    cards.push({
      key: 'webhooks',
      group: 'integrations',
      icon: 'fas fa-plug',
      haystack: this.haystack('webhooks', webhooksEntry ? [webhooksEntry] : [], 'slack'),
      body: () => this.webhooksBody(webhooksEntry),
    });

    if (leftover.length > 0) {
      cards.push({
        key: 'other',
        group: 'integrations',
        icon: 'fas fa-sliders',
        haystack: this.haystack('other', leftover),
        body: () => <div className="ChatAdmin-fields">{leftover.map((entry) => this.buildSettingComponent(entry))}</div>,
      });
    }

    return cards;
  }

  protected haystack(key: string, fields: SettingEntry[], extra = ''): string {
    const parts = [
      this.sectionText(key, 'title'),
      this.sectionText(key, 'help'),
      extra,
      ...fields.map((entry) => [extractText(entry.label), extractText(entry.help as any)].join(' ')),
    ];

    return parts.join(' ').toLowerCase();
  }

  protected sectionText(key: string, part: 'title' | 'help'): string {
    return app.translator.trans(`ramon-chat.admin.sections.${key}_${part}`, {}, true) as string;
  }

  protected toolbar(cards: Card[], searching: boolean): Mithril.Children {
    return (
      <div className="ChatAdmin-toolbar">
        <div
          className="ChatAdmin-tabs"
          role="tablist"
          aria-label={app.translator.trans('ramon-chat.admin.layout.groups_aria', {}, true)}
          onkeydown={(e: KeyboardEvent) => this.onTabKey(e)}
        >
          {GROUPS.map((group) => {
            const count = cards.filter((card) => card.group === group.key).length;
            const active = !searching && group.key === activeGroup;
            const label = app.translator.trans(`ramon-chat.admin.layout.group_${group.key}`, {}, true) as string;

            return (
              <button
                key={group.key}
                type="button"
                role="tab"
                id={`ChatAdmin-tab-${group.key}`}
                aria-selected={active ? 'true' : 'false'}
                aria-controls="ChatAdmin-panel"
                tabindex={active || (searching && group === GROUPS[0]) ? 0 : -1}
                data-group={group.key}
                title={label}
                aria-label={label}
                className={'ChatAdmin-tab' + (active ? ' is-active' : '')}
                onclick={() => this.selectGroup(group.key)}
              >
                <i className={group.icon} aria-hidden="true" />
                <span className="ChatAdmin-tabLabel" aria-hidden="true">
                  {label}
                </span>
                <span className="ChatAdmin-tabCount">{count}</span>
              </button>
            );
          })}
        </div>

        <div className="ChatAdmin-search" role="search">
          <i className="fas fa-magnifying-glass" aria-hidden="true" />
          <input
            type="search"
            className="ChatAdmin-searchInput"
            aria-label={app.translator.trans('ramon-chat.admin.layout.search_placeholder', {}, true)}
            value={query}
            placeholder={app.translator.trans('ramon-chat.admin.layout.search_placeholder', {}, true)}
            oninput={(e: Event) => {
              query = (e.target as HTMLInputElement).value;
            }}
            onkeydown={(e: KeyboardEvent) => {
              if (e.key === 'Escape') query = '';
            }}
          />
          {query ? (
            <button
              type="button"
              className="ChatAdmin-searchClear"
              aria-label={app.translator.trans('ramon-chat.admin.layout.search_clear', {}, true)}
              onclick={() => {
                query = '';
              }}
            >
              <i className="fas fa-xmark" aria-hidden="true" />
            </button>
          ) : null}
        </div>
      </div>
    );
  }

  protected selectGroup(key: GroupKey): void {
    activeGroup = key;
    query = '';

    try {
      localStorage.setItem(TAB_STORAGE_KEY, key);
    } catch {
      activeGroup = key;
    }
  }

  protected onTabKey(e: KeyboardEvent): void {
    const index = GROUPS.findIndex((group) => group.key === activeGroup);
    let next = index;

    if (e.key === 'ArrowRight') next = (index + 1) % GROUPS.length;
    else if (e.key === 'ArrowLeft') next = (index - 1 + GROUPS.length) % GROUPS.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = GROUPS.length - 1;
    else return;

    e.preventDefault();
    this.selectGroup(GROUPS[next].key);
    m.redraw.sync();

    document.getElementById(`ChatAdmin-tab-${GROUPS[next].key}`)?.focus();
  }

  protected sectionBody(section: Section, fields: SettingEntry[]): Mithril.Children {
    return (
      <>
        <div className={'ChatAdmin-fields' + (section.columns === 2 ? ' ChatAdmin-fields--two' : '')}>
          {fields.map((entry) => this.buildSettingComponent(entry))}
        </div>

        {section.key === 'appearance' ? (
          <BrandingPreview
            title={String(this.setting('ramon-chat.title')() ?? '')}
            icon={String(this.setting('ramon-chat.icon')() ?? '')}
            showIcon={this.isOn(this.setting('ramon-chat.show_icon')(), true)}
          />
        ) : null}

        {section.key === 'realtime' && queueKind() !== 'other' ? (
          <p className="ChatAdmin-note">
            <i className="fas fa-bolt" aria-hidden="true" />
            {app.translator.trans(
              queueKind() === 'database'
                ? 'ramon-chat.admin.settings.queue_realtime_inline_database'
                : 'ramon-chat.admin.settings.queue_realtime_inline'
            )}
          </p>
        ) : null}

        {section.warning ? (
          <p className="ChatAdmin-warning">
            <i className="fas fa-triangle-exclamation" aria-hidden="true" />
            {app.translator.trans(section.warning)}
          </p>
        ) : null}

        {section.key === 'uploads' && this.storesOnFofLocal() ? (
          <p className="ChatAdmin-warning">
            <i className="fas fa-triangle-exclamation" aria-hidden="true" />
            {app.translator.trans('ramon-chat.admin.settings.fof_upload_local_warning')}
          </p>
        ) : null}
      </>
    );
  }

  // O formulário guarda "1"/"0" ao carregar, boolean depois de alternar e nada
  // se a setting nunca foi salva.
  protected isOn(raw: unknown, fallback: boolean): boolean {
    if (raw === undefined || raw === null || raw === '') return fallback;

    return raw === '1' || raw === 'true' || raw === true;
  }

  // Lido do formulário ao vivo, para o adapter aparecer assim que o storage
  // muda, sem esperar o save.
  protected fieldShown(key: string): boolean {
    if (key === QUEUE_SETTING) return queueKind() === 'other';

    if (key !== ADAPTER_SETTING) return true;

    return this.setting(STORAGE_SETTING)() === 'fof-upload';
  }

  // O adapter `local` do fof/upload grava no public do próprio fórum, então não
  // ganha nada sem uma CDN na frente. O mapeamento por mime padrão do fof/upload
  // também manda imagens para ele.
  protected storesOnFofLocal(): boolean {
    const adapter = this.setting(ADAPTER_SETTING)() ?? '';

    return this.setting(STORAGE_SETTING)() === 'fof-upload' && (adapter === 'local' || adapter === '');
  }

  // O switch é lido do formulário: desligá-lo esconde o painel antes do save,
  // que acontece junto com o resto da página.
  protected webhooksBody(entry: SettingEntry | undefined): Mithril.Children {
    const enabled = this.isOn(this.setting(WEBHOOKS_SWITCH)(), false);

    return (
      <>
        {entry ? <div className="ChatAdmin-fields ChatAdmin-switch">{this.buildSettingComponent(entry)}</div> : null}

        {enabled ? (
          <WebhooksPanel />
        ) : (
          <p className="ChatAdmin-muted">
            <i className="fas fa-plug-circle-xmark" aria-hidden="true" />
            {app.translator.trans('ramon-chat.admin.webhooks.disabled')}
          </p>
        )}
      </>
    );
  }

  // Durante a busca, o card mostra a aba a que pertence.
  protected card(card: Card, group: GroupKey | null): Mithril.Children {
    const titleId = `ChatAdmin-title-${card.key}`;

    return (
      <section key={card.key} className={'ChatAdmin-section ChatAdmin-section--' + card.key} aria-labelledby={titleId}>
        <header className="ChatAdmin-sectionHeader">
          <span className="ChatAdmin-sectionIcon" aria-hidden="true">
            <i className={card.icon} />
          </span>
          <div className="ChatAdmin-sectionHeading">
            <h3 className="ChatAdmin-sectionTitle" id={titleId}>
              {app.translator.trans(`ramon-chat.admin.sections.${card.key}_title`)}
              {group ? (
                <button type="button" className="ChatAdmin-sectionGroup" onclick={() => this.selectGroup(group)}>
                  {app.translator.trans(`ramon-chat.admin.layout.group_${group}`)}
                </button>
              ) : null}
            </h3>
            <p className="ChatAdmin-sectionHelp">{app.translator.trans(`ramon-chat.admin.sections.${card.key}_help`)}</p>
          </div>
        </header>

        <div className="ChatAdmin-sectionBody">{card.body()}</div>
      </section>
    );
  }
}
