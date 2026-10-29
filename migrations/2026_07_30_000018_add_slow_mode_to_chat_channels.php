<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Slow mode: intervalo mínimo entre mensagens da mesma pessoa no canal.
 *
 * Por canal, porque ritmos diferem; `max_messages_per_second` é proteção contra
 * flood da instalação inteira. Em segundos; 0 desliga.
 */
return [
    // O migrator do Flarum passa um schema Builder; tipar como ConnectionInterface
    // dá TypeError e aborta todas as migrations da extensão.
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_channels', 'slow_mode_seconds')) {
            return;
        }

        $schema->table('chat_channels', function (Blueprint $table) {
            $table->unsignedInteger('slow_mode_seconds')->default(0);
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_channels', 'slow_mode_seconds')) {
            return;
        }

        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('slow_mode_seconds');
        });
    },
];
