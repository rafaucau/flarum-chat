<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Event;

use Flarum\User\User;
use Ramon\Chat\Channel;

/**
 * Uma movimentação inteira, após o commit, agrupada pelo canal de origem.
 *
 * MessageWasMoved continua disparando por mensagem, dentro da transação. Este
 * serve ao push do websocket, que assim manda um evento só e nunca antes do commit.
 */
class MessagesWereMoved
{
    /**
     * @param  array<int, int[]>  $messageIdsBySource  id do canal de origem => ids movidos
     * @param  array<int, Channel>  $sources            id do canal de origem => canal
     * @param  array<int, int>  $threadIds             id da mensagem => thread que ela deixou
     */
    public function __construct(
        public array $messageIdsBySource,
        public array $sources,
        public Channel $target,
        public array $threadIds = [],
        public ?User $actor = null
    ) {
    }
}
