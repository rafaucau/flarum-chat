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
 * Convites pendentes.
 *
 * Uma linha por convidado, apagada ao aceitar, recusar ou cancelar. Separada de
 * `chat_channel_user`: convite não é associação, e as queries de visibilidade e
 * contagem seguem lendo só a tabela de membros.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('chat_channel_invites')) {
            return;
        }

        $schema->create('chat_channel_invites', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('channel_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('inviter_id')->nullable();

            $table->timestamps();

            $table->unique(['channel_id', 'user_id']);
            $table->index(['user_id']);

            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('inviter_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_channel_invites');
    },
];
