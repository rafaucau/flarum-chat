<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\User\Event\Deleting;
use Ramon\Chat\Service\OwnershipSuccession;

/**
 * Conta apagada deixa de ser dona dos canais antes de sumir: cada um vai para o
 * moderador mais antigo, como se o dono tivesse saído. Sem isso a FK só zeraria
 * o dono, sem avisar ninguém.
 */
class HandOverOwnedChannels
{
    public function __construct(
        protected OwnershipSuccession $succession
    ) {
    }

    public function handle(Deleting $event): void
    {
        $this->succession->handOverAll($event->user);
    }
}
