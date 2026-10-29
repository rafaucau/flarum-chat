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
 * `filter[resolved]=1`: inclui as denúncias já resolvidas. Com `0`, só as abertas.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class FlagResolvedFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'resolved';
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

        // `resolved=1` amplia, não estreita: mostra o histórico inteiro. O cliente
        // sempre manda `0` ou `1`; um padrão no searcher não poderia ser desfeito
        // aqui, porque os filtros rodam depois.
        if ($wanted) {
            return;
        }

        $state->getQuery()->whereNull('chat_message_flags.resolved_at');
    }
}
