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
 * Índices para o segundo cursor do polling.
 *
 * `filter[updatedSince]` varria o canal inteiro a cada poll, pois os índices
 * eram de `created_at`. Os índices compostos viram leitura por faixa, o que
 * importa porque a query roda para cada cliente aberto sem websocket.
 *
 * Closure crua porque os helpers de Migration não cobrem índices. Cada índice
 * é detectado pela lista de colunas, não pelo nome gerado pelo Laravel.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('chat_messages')) {
            return;
        }

        $missing = array_filter(
            [['channel_id', 'updated_at'], ['thread_id', 'updated_at']],
            fn (array $columns) => ! $schema->hasIndex('chat_messages', $columns)
        );

        if ($missing === []) {
            return;
        }

        $schema->table('chat_messages', function (Blueprint $table) use ($missing) {
            foreach ($missing as $columns) {
                $table->index($columns);
            }
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasTable('chat_messages')) {
            return;
        }

        $present = array_filter(
            [['channel_id', 'updated_at'], ['thread_id', 'updated_at']],
            fn (array $columns) => $schema->hasIndex('chat_messages', $columns)
        );

        if ($present === []) {
            return;
        }

        $schema->table('chat_messages', function (Blueprint $table) use ($present) {
            foreach ($present as $columns) {
                $table->dropIndex($columns);
            }
        });
    },
];
