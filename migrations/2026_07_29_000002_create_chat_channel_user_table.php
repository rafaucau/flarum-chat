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
        $schema->create('chat_channel_user', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('channel_id');
            $table->unsignedInteger('user_id');

            // following = aparece na sidebar do usuário.
            $table->boolean('following')->default(true);

            // Nível de notificação: 2 = sempre, 1 = só menções (padrão), 0 = nunca.
            $table->unsignedTinyInteger('notification_level')->default(1);

            // Suprime também os badges de não lidas.
            $table->boolean('muted')->default(false);

            // unread_count é desnormalizado para a sidebar não contar linhas por canal.
            $table->unsignedInteger('last_read_message_id')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->unsignedInteger('unread_mentions_count')->default(0);
            $table->dateTime('last_viewed_at')->nullable();

            // Participantes de canal direto podem sair sem apagar o histórico.
            $table->dateTime('joined_at')->nullable();
            $table->dateTime('left_at')->nullable();

            $table->timestamps();

            $table->unique(['channel_id', 'user_id']);
            $table->index(['user_id', 'following']);
            $table->index(['user_id', 'unread_count']);

            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_channel_user');
    },
];
