<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Ramon\Chat\MessageFlag;

/**
 * O que o fórum diz ao cliente sobre o chat do ator: cada controle da interface
 * é desenhado a partir disso, e o servidor continua sendo quem recusa.
 */
class ForumResourceFields
{
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('canUseChat')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('useChat')),

            Schema\Boolean::make('canCreateChatChannel')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('createChannel')),

            // Drives the @here / @all rows in the composer's autocomplete. Offering
            // them to someone the server would refuse is worse than not offering.
            Schema\Boolean::make('canMentionChatChannelWide')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.mentionChannelWide')),

            // Gates the "move messages" control in the selection bar, which is the
            // one selection action MoveMessagesController requires `moderate` for.
            Schema\Boolean::make('canModerateChat')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.moderate')),

            // The channel form's audience switches (auto-join, auto-join on reply,
            // announcing discussions). ChannelResource refuses a change to them
            // from anyone else, so offering them would only produce an error.
            Schema\Boolean::make('canCurateChatChannels')
                ->get(fn ($forum, Context $context) => $context->getActor()->isAdmin()
                    || $context->getActor()->hasPermission('ramon-chat.moderate')
                    || $context->getActor()->hasPermission('ramon-chat.editChannel')),

            // The paperclip is drawn from this. The permission was enforced only in
            // UploadController, so someone without it still saw the control and got
            // a 403 on use — the server was right and the interface was lying.
            // The sticker button is drawn from this. Enforced server-side too, in
            // the message dispatcher — a hidden button is a courtesy, not a gate.
            Schema\Boolean::make('canSendChatStickers')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.sendStickers')),

            Schema\Boolean::make('canUploadChatFiles')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.upload')),

            Schema\Boolean::make('canStartChatDirect')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('startDirect')),

            // Draws the report button. The policy still decides per message — you
            // cannot report your own, or one already deleted — so this only says
            // whether the actor may report anything at all.
            Schema\Boolean::make('canFlagChatMessages')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.flagMessage')),

            // The composer restarts its own countdown after a send rather than
            // waiting for the server to tell it to, so it needs to know who the
            // wait does not apply to. `slowModeRemaining` already answers this on
            // load — this is the same answer, for the moment there is no fresh
            // channel payload to read it from.
            Schema\Boolean::make('canBypassChatSlowMode')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.bypassSlowMode')),

            // The badge on the moderation link, and the link itself. Counted only
            // for moderators, so the ordinary page load never runs this query.
            Schema\Integer::make('chatOpenFlagsCount')
                ->get(function ($forum, Context $context) {
                    $actor = $context->getActor();

                    if (! $actor->hasPermission('ramon-chat.moderate')) {
                        return 0;
                    }

                    // `whereHas` before `whereNull`: the latter is typed as
                    // returning a query builder rather than an Eloquent one, so
                    // the relation method is not available after it.
                    //
                    // `whereVisibleTo` is registered by Flarum's
                    // ScopeVisibilityTrait as a model scope, which static analysis
                    // cannot see on a builder instance.
                    return MessageFlag::query()
                        // @phpstan-ignore method.notFound (Flarum model scope)
                        ->whereHas('message', fn ($query) => $query->whereVisibleTo($actor))
                        ->whereNull('resolved_at')
                        ->count();
                }),
        ];
    }
}
