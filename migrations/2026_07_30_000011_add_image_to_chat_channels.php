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
 * Imagem do canal, alternativa ao emoji.
 *
 * Caminho no disco `flarum-assets`, não URL, como logo e favicon do core: o fórum
 * pode mudar de host sem quebrar os ícones. Fica ao lado de `emoji`; o cliente
 * prefere a imagem, e remover o upload volta ao emoji.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->string('image_path', 100)->nullable()->after('emoji');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    },
];
