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
 * Mensagens fixadas.
 *
 * Colunas na mensagem, não pivot: o pin vale para o canal todo, não por usuário,
 * e a lista vira uma query indexada simples.
 *
 * `pinned_by_id` é zerado junto com `pinned_at`, que é a única fonte de verdade.
 * ON DELETE SET NULL: apagar a conta do moderador não pode apagar os pins.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_messages', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('edited_by_id');
            $table->integer('pinned_by_id')->unsigned()->nullable()->after('pinned_at');

            $table->foreign('pinned_by_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            // A lista de fixadas é sempre por canal.
            $table->index(['channel_id', 'pinned_at']);
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['pinned_by_id']);
            $table->dropIndex(['channel_id', 'pinned_at']);
            $table->dropColumn(['pinned_at', 'pinned_by_id']);
        });
    },
];
