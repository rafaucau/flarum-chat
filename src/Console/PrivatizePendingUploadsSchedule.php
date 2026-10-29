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
        // Arquivo que devia ser privado vaza até a próxima execução; o comando
        // só enfileira, então rodar de hora em hora é barato.
        $event->hourly();
    }
}
