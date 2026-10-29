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
use Ramon\Chat\MessageFlag;

/**
 * Listagem da fila de moderação. A autorização se repete aqui porque o
 * `Endpoint\Index` monta a query a partir do searcher e descarta a do `scope()`
 * do resource.
 */
class MessageFlagSearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        // Em comandos separados: `select` e `whereRaw` são tipados como retornando
        // o query builder base, e o método deve um Builder do Eloquent.
        $query = MessageFlag::query();
        $query->select('chat_message_flags.*');

        if (! $actor->hasPermission('ramon-chat.moderate')) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        // Herda a visibilidade da mensagem: a fila nunca mostra denúncia sobre algo
        // que o ator não poderia ler no canal.
        $query->whereHas('message', function (Builder $sub) use ($actor) {
            // @phpstan-ignore method.notFound (Flarum model scope)
            $sub->whereVisibleTo($actor);
        });

        return $query;
    }
}
