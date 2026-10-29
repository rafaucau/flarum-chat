import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import PermissionGrid from 'flarum/admin/components/PermissionGrid';
import type { PermissionConfig } from 'flarum/admin/components/PermissionGrid';
import type Mithril from 'mithril';

import Webhook from '../common/models/Webhook';
import ChatSettingsPage from './components/ChatSettingsPage';
import WebhooksPanel from './components/WebhooksPanel';
import BotSettings from './components/BotSettings';

export { ChatSettingsPage, WebhooksPanel, BotSettings, Webhook };
export { default as extend } from './extend';

app.initializers.add('ramon-chat', () => {
  const trans = (key: string) => app.translator.trans(`ramon-chat.admin.${key}`);

  // As seções do core escondem a distinção que importa aqui: o que vale para o
  // chat inteiro e o que membros têm nos canais que criam. As linhas do chat
  // saem das seções do core e viram dois blocos; o de membros só existe no modo
  // "membros", já que no modo "administradores" nada nele teria efeito.
  //
  // Feito no permissionItems() porque o registry só conhece as quatro seções do
  // core, e a grade da página da extensão chama o mesmo método.
  extend(PermissionGrid.prototype, 'permissionItems', function (items) {
    const membersMode = app.data.settings['ramon-chat.channel_ownership'] === 'members';

    const ours = new Map<string, PermissionConfig>();

    for (const type of ['view', 'start', 'reply', 'moderate'] as const) {
      if (!items.has(type)) continue;

      const section = items.get(type);

      section.children = section.children.filter((entry) => {
        const permission = 'permission' in entry ? (entry.permission ?? '') : '';

        if (!permission.startsWith('ramon-chat.')) return true;

        ours.set(permission, entry as PermissionConfig);

        return false;
      });
    }

    const pick = (keys: readonly string[]): PermissionConfig[] =>
      keys.map((key) => ours.get(key)).filter((entry): entry is PermissionConfig => Boolean(entry));

    const global = pick(membersMode ? GLOBAL_PERMISSIONS : [...GLOBAL_PERMISSIONS, ...PARTICIPATION_PERMISSIONS]);

    if (global.length > 0) {
      items.add(
        'ramon-chat-global',
        {
          label: heading('permissions.global_heading', 'permissions.global_heading_help'),
          children: global,
        },
        60
      );
    }

    if (!membersMode) return;

    const members = pick([...OWN_CHANNEL_PERMISSIONS, ...PARTICIPATION_PERMISSIONS]);

    if (members.length > 0) {
      items.add(
        'ramon-chat-members',
        {
          label: heading('permissions.members_heading', 'permissions.members_heading_help'),
          children: members,
        },
        55
      );
    }
  });

  // A grade aceita qualquer vnode como label.
  function heading(titleKey: string, helpKey: string): Mithril.Children {
    return m('span.ChatPermissionHeading', [
      m('span.ChatPermissionHeading-title', trans(titleKey)),
      m('span.ChatPermissionHeading-help', trans(helpKey)),
    ]);
  }
});

// Valem para o chat inteiro em qualquer modo. A ordem é a da grade.
const GLOBAL_PERMISSIONS = [
  'ramon-chat.use',
  'ramon-chat.accessPrivateChannels',
  'ramon-chat.startDirect',
  'ramon-chat.mentionChannelWide',
  'ramon-chat.flagMessage',
  'ramon-chat.bypassSlowMode',
  'ramon-chat.pinMessage',
  'ramon-chat.moderate',
  'ramon-chat.inspectChannels',
  'ramon-chat.editChannel',
] as const;

// Globais no modo "administradores"; vão para o bloco de membros no modo
// "membros".
const PARTICIPATION_PERMISSIONS = ['ramon-chat.upload', 'ramon-chat.sendStickers', 'ramon-chat.react', 'ramon-chat.createThread'] as const;

// Só no modo "membros".
const OWN_CHANNEL_PERMISSIONS = ['ramon-chat.createChannel', 'ramon-chat.manageOwnChannels', 'ramon-chat.editOwnChannels'] as const;
