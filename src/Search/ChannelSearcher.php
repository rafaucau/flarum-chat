<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Search;

use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Channel;

/**
 * No Flarum 2 o resource JSON:API não tem filtros (`filters()` é final e lança),
 * então todo `filter[...]` do cliente passa por um searcher e seus filtros.
 */
class ChannelSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        return Channel::whereVisibleTo($actor)->select('chat_channels.*');
    }
}
