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
 * Cargos de cada canal: os criados pelo dono e, só se personalizados, os dois
 * embutidos (dono e moderador).
 *
 * `builtin` null é cargo do dono. Nome e cor null num embutido usam o padrão
 * (nome traduzido e cor do tema).
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('chat_channel_ranks')) {
            return;
        }

        $schema->create('chat_channel_ranks', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('channel_id');
            $table->string('builtin', 16)->nullable();
            $table->string('name', 32)->nullable();
            $table->string('color', 7)->nullable();
            $table->string('icon', 64)->nullable();
            $table->boolean('show_badge')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['channel_id', 'builtin']);
            $table->index(['channel_id', 'position']);

            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_channel_ranks');
    },
];
