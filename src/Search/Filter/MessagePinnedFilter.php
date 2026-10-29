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

/**
 * `filter[pinned]=1`: só as fixadas. Com `filter[channel]` monta a lista de fixadas
 * do canal; negado (`filter[-pinned]=1`), as exclui.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MessagePinnedFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'pinned';
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

        // `pinned_at` é a fonte da verdade; `pinned_by_id` é só autoria e pode
        // virar null quando o usuário é apagado.
        $query = $state->getQuery();

        if ($wanted xor $negate) {
            $query->whereNotNull('chat_messages.pinned_at');
        } else {
            $query->whereNull('chat_messages.pinned_at');
        }
    }
}
