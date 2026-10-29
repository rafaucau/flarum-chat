<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('chat_drafts', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('user_id');
            $table->unsignedInteger('channel_id');

            // Canal e cada thread têm rascunhos independentes; thread_id entra na chave.
            $table->unsignedInteger('thread_id')->nullable();

            $table->mediumText('content')->nullable();

            $table->timestamps();

            // O MySQL trata NULL como distinto em índice único; rascunhos de canal
            // (thread_id NULL) são deduplicados no model, não aqui.
            $table->unique(['user_id', 'channel_id', 'thread_id'], 'chat_drafts_scope_unique');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on('chat_threads')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_drafts');
    },
];
