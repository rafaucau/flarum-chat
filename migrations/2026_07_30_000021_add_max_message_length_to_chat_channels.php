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
 * Limite de tamanho de mensagem por canal.
 *
 * Null segue `ramon-chat.max_message_length` do fórum (não é "ilimitado"), então
 * mudar o do fórum vale para todos os canais sem número próprio.
 *
 * Separado do slow mode: este limita quanto se diz, o outro com que frequência.
 */
return [
    // O migrator do Flarum passa um schema Builder; tipar como ConnectionInterface
    // dá TypeError e aborta todas as migrations da extensão.
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_channels', 'max_message_length')) {
            return;
        }

        $schema->table('chat_channels', function (Blueprint $table) {
            $table->unsignedInteger('max_message_length')->nullable();
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_channels', 'max_message_length')) {
            return;
        }

        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('max_message_length');
        });
    },
];
