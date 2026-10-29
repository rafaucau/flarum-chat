<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Console;

use Illuminate\Console\Scheduling\Event;

class PrivatizePendingUploadsSchedule
{
    public function __invoke(Event $event): void
    {
        // A file that should be private and is not is a leak until the next
        // run, so hourly; the command only queues, so it is cheap.
        $event->hourly();
    }
}
