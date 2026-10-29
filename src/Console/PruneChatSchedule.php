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
        // Retention is housekeeping: nightly is frequent enough, and 03:30
        // keeps a destructive job away from peak traffic.
        $event->daily()->at('03:30');
    }
}
