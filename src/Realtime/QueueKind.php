<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Flarum\Queue\RoutingQueue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\SyncQueue;

/**
 * Tipo de fila do fórum, do ponto de vista do push realtime. Desembrulha o
 * RoutingQueue do core primeiro. `sync` roda inline e `database` só roda uma vez
 * por minuto pelo `schedule:run`; só uma fila com worker contínuo (fof/redis etc.)
 * recebe o push sem atrasar.
 */
final class QueueKind
{
    public const SYNC = 'sync';

    public const DATABASE = 'database';

    public const OTHER = 'other';

    public static function of(mixed $queue): string
    {
        if (class_exists(RoutingQueue::class) && $queue instanceof RoutingQueue) {
            $queue = $queue->getDriver();
        }

        if ($queue instanceof SyncQueue) {
            return self::SYNC;
        }

        if ($queue instanceof DatabaseQueue) {
            return self::DATABASE;
        }

        return self::OTHER;
    }

    /**
     * Se o push pode sair da request sem chegar atrasado.
     */
    public static function defers(mixed $queue): bool
    {
        return self::of($queue) === self::OTHER;
    }
}
