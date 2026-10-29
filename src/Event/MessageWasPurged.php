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
 * Mensagem apagada de vez, sem tombstone.
 *
 * Leva ids e o canal em vez da Message: quando o listener roda, a linha já não
 * existe, e o broadcaster precisa do canal para saber quem estava na conversa.
 */
class MessageWasPurged
{
    public function __construct(
        public int $messageId,
        public Channel $channel,
        public ?int $threadId = null,
        public ?User $actor = null
    ) {
    }
}
