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
 * Anuncia no canal as novas discussões da categoria vinculada.
 *
 * Por canal, ao lado de `auto_join_on_reply`, porque só faz sentido com tag.
 * Desligado por padrão: bem-vindo numa categoria calma, insuportável numa movimentada.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->boolean('post_discussions')->default(false)->after('auto_join_on_reply');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('post_discussions');
        });
    },
];
