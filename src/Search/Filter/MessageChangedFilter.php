<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Search\Filter;

use Carbon\Carbon;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * `filter[updatedSince]=1754400000`: mensagens alteradas desde um ponto. O polling
 * usa junto com `greaterThan`, que só traz as novas, para pegar edições, reações e
 * fixações. O limite é inclusivo porque `updated_at` tem precisão de segundo.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MessageChangedFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'updatedSince';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $timestamp = (int) (is_array($value) ? reset($value) : $value);

        if ($timestamp <= 0) {
            return;
        }

        // UTC explícito: a coluna é gravada em UTC, e o fuso do php.ini deslocaria
        // a comparação.
        $state->getQuery()->where(
            'chat_messages.updated_at',
            $negate ? '<' : '>=',
            Carbon::createFromTimestamp($timestamp, 'UTC')
        );
    }
}
