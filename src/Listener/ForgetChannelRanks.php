<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Ramon\Chat\Event\ChannelModeratorChanged;
use Ramon\Chat\Event\ChannelOwnershipTransferred;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Service\ChannelRanks;

/**
 * Descarta o cache de cargos do canal quando muda o dono, os moderadores ou
 * quem está no canal (só membros presentes exibem cargo).
 *
 * Registrado antes do realtime, que lê os cargos já reconstruídos para avisar a sala.
 */
class ForgetChannelRanks
{
    public function __construct(
        protected ChannelRanks $ranks
    ) {
    }

    public function handle(ChannelModeratorChanged|ChannelOwnershipTransferred|UserJoinedChannel|UserLeftChannel $event): void
    {
        $this->ranks->forget($event->channel);
    }
}
