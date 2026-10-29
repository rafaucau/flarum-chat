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

  // ── How the grid is organised ─────────────────────────────────────────────
  // Core sorts every extension's rows into Read / Create / Participate /
  // Moderate. For the chat that hides the one distinction an operator needs:
  // what applies to the whole chat, and what members get in the channels they
  // create and run. So the chat's rows are lifted out of the core sections and
  // laid out as two blocks of its own, "Chat: global" first and "Chat: channels
  // run by members" below it. Each block's heading says in plain words when it
  // is in force. The second block exists only while channels are in members'
  // hands (the ownership setting): in "administrators" mode nothing in it would
  // do anything, so it is not shown, and the participation rows it would hold
  // sit in the global block instead.
  //
  // Done in permissionItems() rather than through registerPermission(): the
  // registry only knows the four core sections, and the extension page's grid
  // calls this same method, so both grids get the same layout.
  extend(PermissionGrid.prototype, 'permissionItems', function (items) {
    const membersMode = app.data.settings['ramon-chat.channel_ownership'] === 'members';

    // Lift every chat row out of the core sections, keeping its config.
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

  /**
   * A section heading with a second line saying when the block is in force.
   *
   * Hyperscript rather than JSX because this file is plain TypeScript; the
   * grid accepts any vnode as a label.
   */
  function heading(titleKey: string, helpKey: string): Mithril.Children {
    return m('span.ChatPermissionHeading', [
      m('span.ChatPermissionHeading-title', trans(titleKey)),
      m('span.ChatPermissionHeading-help', trans(helpKey)),
    ]);
  }
});

/**
 * Applies to the whole chat, in every channel, whatever the ownership mode.
 * Listed top to bottom in the order the grid shows them: reading, then
 * taking part, then moderating.
 */
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

/**
 * What members do inside channels: files, stickers, reactions, threads. Global
 * while administrators run the channels; part of the members block once
 * members do.
 */
const PARTICIPATION_PERMISSIONS = ['ramon-chat.upload', 'ramon-chat.sendStickers', 'ramon-chat.react', 'ramon-chat.createThread'] as const;

/** Creating and running one's own channels. Members mode only. */
const OWN_CHANNEL_PERMISSIONS = ['ramon-chat.createChannel', 'ramon-chat.manageOwnChannels', 'ramon-chat.editOwnChannels'] as const;
