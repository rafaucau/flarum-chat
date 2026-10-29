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
use Ramon\Chat\Message;

/**
 * `filter[q]=termo`: busca de mensagens, num canal com `filter[channel]` ou em
 * todos os visíveis. O scope do searcher já restringe a visibilidade.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class MessageTextFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'q';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $term = trim((string) (is_array($value) ? reset($value) : $value));

        if ($term === '') {
            return;
        }

        // Escapa os curingas do LIKE: buscar "100%" não casa com tudo.
        $like = '%'.addcslashes($term, '\\%_').'%';

        $state->getQuery()
            // Apagadas e de sistema nunca são o que se procura.
            ->whereNull('chat_messages.deleted_at')
            ->where('chat_messages.type', Message::TYPE_TEXT)
            ->where('chat_messages.content', $negate ? 'not like' : 'like', $like);
    }
}
