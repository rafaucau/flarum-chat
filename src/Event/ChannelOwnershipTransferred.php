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
use Ramon\Chat\ChannelTransfer;

/**
 * O canal trocou de dono. Disparado após o commit: `channel` já aponta para o
 * novo dono. Vem de uma transferência aceita (`transfer`; o dono anterior, se
 * ainda for membro, vira moderador) ou de sucessão (`inherited`).
 */
class ChannelOwnershipTransferred
{
    public function __construct(
        public Channel $channel,
        public User $newOwner,
        public ?User $previousOwner,
        public ?ChannelTransfer $transfer,
        public ?User $initiator = null,
        public bool $inherited = false
    ) {
    }
}
