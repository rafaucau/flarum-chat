<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Console;

use Illuminate\Console\Scheduling\Event;

class PruneChatSchedule
{
    public function __invoke(Event $event): void
    {
        // 03:30 mantém um job destrutivo longe do horário de pico.
        $event->daily()->at('03:30');
    }
}
