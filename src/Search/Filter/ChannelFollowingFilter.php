<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Search\Filter;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Channel;

/**
 * `filter[following]=1` — the actor's sidebar list.
 *
 * Guests can never hold a membership, so for them the filter resolves to "nothing"
 * rather than being silently ignored.
 *
 * Only ever narrows the visible set: ChannelSearcher starts from
 * `whereVisibleTo`, which is what keeps a channel for everyone out of the list
 * of someone who cannot see it.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class ChannelFollowingFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'following';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $wanted = filter_var(
            is_array($value) ? reset($value) : $value,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($wanted === null) {
            return;
        }

        if ($negate) {
            $wanted = ! $wanted;
        }

        $actor = $state->getActor();
        $query = $state->getQuery();

        if (! $actor->exists) {
            if ($wanted) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $exists = function ($sub) use ($actor) {
            $sub->selectRaw(1)
                ->from('chat_channel_user')
                ->whereColumn('chat_channel_user.channel_id', 'chat_channels.id')
                ->where('chat_channel_user.user_id', $actor->id)
                ->where('chat_channel_user.following', true)
                ->whereNull('chat_channel_user.left_at');
        };

        // A channel for everyone is followed by whoever has no row in it at all;
        // the row someone gets by leaving is what takes it out of their list.
        $implied = function (Builder $query) use ($actor) {
            Channel::onlyForEveryone($query->getQuery())
                ->whereNotExists(function ($sub) use ($actor) {
                    $sub->selectRaw(1)
                        ->from('chat_channel_user')
                        ->whereColumn('chat_channel_user.channel_id', 'chat_channels.id')
                        ->where('chat_channel_user.user_id', $actor->id);
                });
        };

        if ($wanted) {
            $query->where(fn ($query) => $query->whereExists($exists)->orWhere($implied));
        } else {
            $query->whereNotExists($exists)->whereNot($implied);
        }
    }
}
