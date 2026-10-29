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
 * Índices para o stream, a query mais executada da extensão.
 *
 * O canal abre com `where channel_id = ? order by id desc limit 50`. Sem índice
 * começando em `channel_id, id`, o MySQL fazia filesort de todas as mensagens do
 * canal. Com `(channel_id, id)` vira backward index scan que para em 50 linhas;
 * `(thread_id, id)` faz o mesmo no painel de thread.
 *
 * Closure crua porque os helpers de Migration não cobrem índices. Cada índice
 * é detectado pela lista de colunas, não pelo nome gerado pelo Laravel.
 *
 * Em `chat_messages` grande é DDL numa tabela muito lida: rode num horário calmo.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('chat_messages')) {
            return;
        }

        $missing = array_filter(
            [['channel_id', 'id'], ['thread_id', 'id']],
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
            [['channel_id', 'id'], ['thread_id', 'id']],
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
