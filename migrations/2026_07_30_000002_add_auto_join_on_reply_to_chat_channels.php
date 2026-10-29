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
 * Opcional: inscreve o usuário no canal na primeira resposta numa discussão
 * da categoria vinculada.
 *
 * Diferente de `auto_join`, que inclui todo mundo de antemão: cresce por
 * interesse, menos intrusivo em fórum grande. Só faz sentido com tag.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->boolean('auto_join_on_reply')->default(false)->after('auto_join');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('auto_join_on_reply');
        });
    },
];
