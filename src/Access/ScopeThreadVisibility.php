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
use Ramon\Chat\Thread;

/**
 * Threads herdam a visibilidade do canal.
 */
class ScopeThreadVisibility
{
    /**
     * A mesma regra do SQL abaixo, em PHP, para uma thread já carregada. As duas
     * precisam andar juntas.
     */
    public static function rowVisibleTo(Thread $thread, bool $channelVisible): bool
    {
        return $channelVisible && $thread->deleted_at === null;
    }

    public function __invoke(User $actor, Builder $query): void
    {
        $query
            ->whereNull('chat_threads.deleted_at')
            ->whereIn('chat_threads.channel_id', function ($sub) use ($actor) {
                $channels = Channel::query()
                    ->setQuery($sub->from('chat_channels'))
                    ->whereVisibleTo($actor)
                    ->select('chat_channels.id');

                ScopeChannelVisibility::restrictContents($channels, $actor);
            });
    }
}
