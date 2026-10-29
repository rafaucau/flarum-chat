import Extend from "flarum/common/extenders";

import commonExtend from "../common/extend";
import ChannelInviteNotification from "./components/ChannelInviteNotification";
import ChannelInviteDeclinedNotification from "./components/ChannelInviteDeclinedNotification";
import MessageFlaggedNotification from "./components/MessageFlaggedNotification";
import ModeratorPromotedNotification from "./components/ModeratorPromotedNotification";
import OwnershipTransferNotification from "./components/OwnershipTransferNotification";
import OwnershipTransferDeclinedNotification from "./components/OwnershipTransferDeclinedNotification";
import OwnershipInheritedNotification from "./components/OwnershipInheritedNotification";

export default [
  ...commonExtend,

  new Extend.Notification()
    .add("chatChannelInvite", ChannelInviteNotification)
    .add("chatChannelInviteDeclined", ChannelInviteDeclinedNotification)
    .add("chatMessageFlagged", MessageFlaggedNotification)
    .add("chatModeratorPromoted", ModeratorPromotedNotification)
    .add("chatOwnershipTransfer", OwnershipTransferNotification)
    .add("chatOwnershipTransferDeclined", OwnershipTransferDeclinedNotification)
    .add("chatOwnershipInherited", OwnershipInheritedNotification),
];
