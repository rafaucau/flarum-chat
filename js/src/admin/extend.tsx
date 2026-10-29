import app from "flarum/admin/app";
import Extend from "flarum/common/extenders";
import isExtensionEnabled from "flarum/admin/utils/isExtensionEnabled";

import commonExtend from "../common/extend";
import Webhook from "../common/models/Webhook";
import ChatSettingsPage, {
  ADAPTER_SETTING,
  STORAGE_SETTING,
} from "./components/ChatSettingsPage";

const trans = (key: string) => app.translator.trans(`ramon-chat.admin.${key}`);
const text = (key: string) =>
  app.translator.trans(`ramon-chat.admin.${key}`, {}, true) as string;

// Os campos são registrados do jeito comum, então o registry continua sendo o
// único lugar onde cada um é definido; a ChatSettingsPage só os distribui em
// cards com título em vez da coluna única da ExtensionPage do core.
export default [
  ...commonExtend,

  new Extend.Store().add("chat-webhooks", Webhook),

  new Extend.Admin()
    .page(ChatSettingsPage)

    // ── Channels ──────────────────────────────────────────────────────────────
    // Who creates and runs channels is the decision the permission grid depends
    // on: in "administrators" mode nobody else creates a channel, whatever the
    // grid says; in "members" mode whoever may create a channel runs the ones
    // they created, and the grid grows a second block for exactly those rows.
    .setting(() => ({
      setting: "ramon-chat.channel_ownership",
      type: "select",
      options: {
        admin: text("settings.channel_ownership_admin"),
        members: text("settings.channel_ownership_members"),
      },
      default: "admin",
      label: trans("settings.channel_ownership"),
      help: trans("settings.channel_ownership_help"),
    }))
    .setting(() => ({
      setting: "ramon-chat.allow_archiving_channels",
      type: "boolean",
      label: trans("settings.allow_archiving_channels"),
      help: trans("settings.allow_archiving_channels_help"),
    }))
    .setting(() => ({
      setting: "ramon-chat.threading_default",
      type: "boolean",
      label: trans("settings.threading_default"),
      help: trans("settings.threading_default_help"),
    }))

    // ── Appearance ────────────────────────────────────────────────────────────
    .setting(() => ({
      setting: "ramon-chat.title",
      type: "string",
      label: trans("settings.title_label"),
      help: trans("settings.title_help"),
      placeholder: "Chat",
    }))
    .setting(() => ({
      setting: "ramon-chat.icon",
      type: "string",
      label: trans("settings.icon_label"),
      help: trans("settings.icon_help"),
      placeholder: "fas fa-comments",
    }))
    .setting(() => ({
      setting: "ramon-chat.show_icon",
      type: "boolean",
      label: trans("settings.show_icon_label"),
      help: trans("settings.show_icon_help"),
    }))

    // ── Messages ──────────────────────────────────────────────────────────────
    .setting(() => ({
      setting: "ramon-chat.max_message_length",
      type: "number",
      label: trans("settings.max_message_length"),
      help: trans("settings.max_message_length_help"),
      min: 1,
    }))
    .setting(() => ({
      setting: "ramon-chat.min_message_length",
      type: "number",
      label: trans("settings.min_message_length"),
      min: 1,
    }))
    .setting(() => ({
      setting: "ramon-chat.max_messages_per_second",
      type: "number",
      label: trans("settings.max_messages_per_second"),
      help: trans("settings.max_messages_per_second_help"),
      min: 0,
    }))
    .setting(() => ({
      setting: "ramon-chat.message_edit_window_minutes",
      type: "number",
      label: trans("settings.message_edit_window_minutes"),
      help: trans("settings.message_edit_window_help"),
      min: 0,
    }))

    // ── Uploads ───────────────────────────────────────────────────────────────
    .setting(() => ({
      setting: "ramon-chat.allow_uploads",
      type: "boolean",
      label: trans("settings.allow_uploads"),
      help: trans("settings.allow_uploads_help"),
    }))
    .setting(() => ({
      setting: "ramon-chat.max_upload_size",
      type: "number",
      label: trans("settings.max_upload_size"),
      help: trans("settings.max_upload_size_help"),
      min: 0,
    }))

    // ── Retention ─────────────────────────────────────────────────────────────
    .setting(() => ({
      setting: "ramon-chat.channel_retention_days",
      type: "number",
      label: trans("settings.channel_retention_days"),
      help: trans("settings.channel_retention_help"),
      min: 0,
    }))
    .setting(() => ({
      setting: "ramon-chat.dm_retention_days",
      type: "number",
      label: trans("settings.dm_retention_days"),
      help: trans("settings.dm_retention_help"),
      min: 0,
    }))

    // ── Realtime and sound ────────────────────────────────────────────────────
    .setting(() => ({
      setting: "ramon-chat.queue_realtime",
      type: "boolean",
      label: trans("settings.queue_realtime"),
      help: trans("settings.queue_realtime_help"),
    }))

    // A select rather than a boolean plus a file field: the choice is between two
    // shipped sounds and silence, and 'none' is a value of the same setting rather
    // than a second switch that could contradict it.
    .setting(() => ({
      setting: "ramon-chat.notification_sound",
      type: "select",
      options: {
        none: text("settings.sound_none"),
        chime: text("settings.sound_chime"),
        alert: text("settings.sound_alert"),
      },
      default: "chime",
      label: trans("settings.sound"),
      help: trans("settings.sound_help"),
    }))

    // ── Webhooks ──────────────────────────────────────────────────────────────
    // The switch for the feature. Drawn at the top of the webhooks card, so the
    // records under it are read as "what this switch turns on".
    .setting(() => ({
      setting: "ramon-chat.webhooks_enabled",
      type: "boolean",
      label: trans("settings.webhooks_enabled"),
      help: trans("settings.webhooks_enabled_help"),
    }))

    // ── Permissions ───────────────────────────────────────────────────────────
    // Registered under core sections like every other row, so the registry
    // knows the extension has permissions at all (the extension page draws its
    // grid only then). Where they are actually shown is decided below, in the
    // permissionItems extension.
    .permission(
      () => ({
        icon: "fas fa-comments",
        label: trans("permissions.use"),
        permission: "ramon-chat.use",
      }),
      "view",
      95,
    )
    .permission(
      () => ({
        icon: "fas fa-lock-open",
        label: trans("permissions.access_private_channels"),
        permission: "ramon-chat.accessPrivateChannels",
      }),
      "view",
      94,
    )
    .permission(
      () => ({
        icon: "fas fa-envelope",
        label: trans("permissions.start_direct"),
        permission: "ramon-chat.startDirect",
      }),
      "start",
      94,
    )
    .permission(
      () => ({
        icon: "fas fa-note-sticky",
        label: trans("permissions.send_stickers"),
        permission: "ramon-chat.sendStickers",
      }),
      "start",
      61,
    )
    .permission(
      () => ({
        icon: "fas fa-paperclip",
        label: trans("permissions.upload"),
        permission: "ramon-chat.upload",
      }),
      "reply",
      95,
    )
    .permission(
      () => ({
        icon: "far fa-face-smile",
        label: trans("permissions.react"),
        permission: "ramon-chat.react",
      }),
      "reply",
      94,
    )
    .permission(
      () => ({
        icon: "fas fa-at",
        label: trans("permissions.mention_channel_wide"),
        permission: "ramon-chat.mentionChannelWide",
      }),
      "reply",
      93,
    )
    .permission(
      () => ({
        icon: "fas fa-comments",
        label: trans("permissions.create_thread"),
        permission: "ramon-chat.createThread",
      }),
      "reply",
      92,
    )
    .permission(
      () => ({
        icon: "fas fa-gauge-high",
        label: trans("permissions.bypass_slow_mode"),
        permission: "ramon-chat.bypassSlowMode",
      }),
      "moderate",
      94,
    )
    .permission(
      () => ({
        icon: "fas fa-flag",
        label: trans("permissions.flag_message"),
        permission: "ramon-chat.flagMessage",
      }),
      "reply",
      91,
    )
    .permission(
      () => ({
        icon: "fas fa-thumbtack",
        label: trans("permissions.pin_message"),
        permission: "ramon-chat.pinMessage",
      }),
      "moderate",
      96,
    )
    .permission(
      () => ({
        icon: "fas fa-shield-halved",
        label: trans("permissions.moderate"),
        permission: "ramon-chat.moderate",
      }),
      "moderate",
      95,
    )
    // Observing a room unnoticed: no place in the member list, no arrival or
    // departure announced, no notification written. Separate from `moderate`
    // so an administration or audit group can hold it on its own.
    .permission(
      () => ({
        icon: "fas fa-user-secret",
        label: trans("permissions.inspect_channels"),
        permission: "ramon-chat.inspectChannels",
      }),
      "moderate",
      92,
    )
    .permission(
      () => ({
        icon: "fas fa-plus",
        label: trans("permissions.create_channel"),
        permission: "ramon-chat.createChannel",
      }),
      "start",
      93,
    )
    .permission(
      () => ({
        icon: "fas fa-user-shield",
        label: trans("permissions.manage_own_channels"),
        permission: "ramon-chat.manageOwnChannels",
      }),
      "start",
      92,
    )
    .permission(
      () => ({
        icon: "fas fa-user-pen",
        label: trans("permissions.edit_own_channels"),
        permission: "ramon-chat.editOwnChannels",
      }),
      "start",
      91,
    )
    .permission(
      () => ({
        icon: "fas fa-pen-to-square",
        label: trans("permissions.edit_channel"),
        permission: "ramon-chat.editChannel",
      }),
      "moderate",
      93,
    )

    // ── Attachment storage through fof/upload ───────────────────────────────
    // Só com o fof/upload ligado: sem ele o backend grava no disco local seja
    // qual for a opção, e uma escolha que não muda nada engana. A lista de
    // adapters vem do payload do admin (Content\FofUploadAdapters).
    .setting(() =>
      isExtensionEnabled("fof-upload")
        ? {
            setting: STORAGE_SETTING,
            type: "select",
            options: {
              local: text("settings.upload_storage_local"),
              "fof-upload": text("settings.upload_storage_fof"),
            },
            default: "local",
            label: trans("settings.upload_storage"),
            help: trans("settings.upload_storage_help"),
          }
        : null,
    )
    .setting(() =>
      isExtensionEnabled("fof-upload")
        ? {
            setting: ADAPTER_SETTING,
            type: "select",
            options: adapterOptions(),
            default: "",
            label: trans("settings.fof_upload_adapter"),
            help: trans("settings.fof_upload_adapter_help"),
          }
        : null,
    ),
];

function adapterOptions(): Record<string, string> {
  const adapters = Array.isArray(app.data.ramonChatFofUploadAdapters)
    ? (app.data.ramonChatFofUploadAdapters as string[])
    : [];

  const options: Record<string, string> = {
    "": text("settings.fof_upload_adapter_mapping"),
  };

  for (const key of adapters) {
    options[key] = adapterLabel(key);
  }

  return options;
}

/**
 * fof/upload's own name for an adapter, falling back to the key when it has
 * none (a third-party adapter with no translation).
 */
function adapterLabel(key: string): string {
  const translationKey = `fof-upload.admin.upload_methods.${key}`;
  const label = app.translator.trans(translationKey, {}, true) as string;

  return label && label !== translationKey ? label : key;
}
