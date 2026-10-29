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
 * Transferência encerrada sem troca de dono: recusada, cancelada ou substituída.
 * A linha já foi apagada; `transfer` é a cópia em memória, para saber as partes.
 */
class OwnershipTransferEnded
{
    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const REPLACED = 'replaced';

    public function __construct(
        public Channel $channel,
        public ChannelTransfer $transfer,
        public string $reason,
        public ?User $actor = null
    ) {
    }
}
