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
use Ramon\Chat\Access\ScopeChannelVisibility;
use Ramon\Chat\Channel;

/**
 * `filter[chatChannel]=7` na busca de usuários: só quem está no canal (autocomplete
 * do `@`). O canal passa por `whereVisibleTo` antes, senão qualquer id serviria para
 * listar membros de um canal privado. Membros ocultos ficam de fora.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class UserChatChannelFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'chatChannel';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $channelId = (int) (is_array($value) ? reset($value) : $value);

        if ($channelId <= 0) {
            return;
        }

        $actor = $state->getActor();

        $channel = Channel::query()
            ->whereVisibleTo($actor)
            ->find($channelId);

        if ($channel === null || ! ScopeChannelVisibility::readsContents($actor, $channel)) {
            // Falha fechado: nada, em vez de todo o fórum.
            $state->getQuery()->whereRaw('1 = 0');

            return;
        }

        $state->getQuery()->{$negate ? 'whereNotExists' : 'whereExists'}(function ($query) use ($channelId) {
            $query->selectRaw(1)
                ->from('chat_channel_user')
                ->whereColumn('chat_channel_user.user_id', 'users.id')
                ->where('chat_channel_user.channel_id', $channelId)
                ->whereNull('chat_channel_user.left_at')
                ->where('chat_channel_user.hidden', false);
        });
    }
}
