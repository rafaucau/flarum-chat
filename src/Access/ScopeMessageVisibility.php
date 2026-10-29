<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Channel;
use Ramon\Chat\Message;

/**
 * Mensagem é visível quando o canal é. Apagadas continuam visíveis ao autor e à
 * moderação, como posts de discussão. A regra existe em SQL (`__invoke`) e em PHP
 * (`rowVisibleTo`, usado pela MessagePolicy); as duas precisam andar juntas.
 */
class ScopeMessageVisibility
{
    /**
     * `$channelVisible` vem de fora porque é a mesma resposta para todo o canal.
     */
    public static function rowVisibleTo(User $actor, Message $message, bool $channelVisible): bool
    {
        if (! $channelVisible) {
            return false;
        }

        // Ver o canal não é ler: o convidado de um canal privado vê a linha, mas
        // nenhuma mensagem até aceitar.
        if ($message->channel !== null && ! ScopeChannelVisibility::readsContents($actor, $message->channel)) {
            return false;
        }

        if ($actor->hasPermission('ramon-chat.moderate')) {
            return true;
        }

        if ($message->deleted_at === null) {
            return true;
        }

        return $actor->exists && (int) $message->user_id === (int) $actor->id;
    }

    public function __invoke(User $actor, Builder $query): void
    {
        $query->whereIn('chat_messages.channel_id', function ($sub) use ($actor) {
            $channels = Channel::query()
                ->setQuery($sub->from('chat_channels'))
                ->whereVisibleTo($actor)
                ->select('chat_channels.id');

            ScopeChannelVisibility::restrictContents($channels, $actor);
        });

        if ($actor->hasPermission('ramon-chat.moderate')) {
            return;
        }

        $query->where(function (Builder $query) use ($actor) {
            $query->whereNull('chat_messages.deleted_at');

            if ($actor->exists) {
                $query->orWhere('chat_messages.user_id', $actor->id);
            }
        });
    }
}
