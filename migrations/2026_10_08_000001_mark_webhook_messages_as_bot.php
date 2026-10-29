<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Entregas de webhook gravadas como texto sem autor apareciam como conta
 * apagada. Viram mensagens de bot, desenhadas com nome e avatar do bot.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()
            ->table('chat_messages')
            ->whereNotNull('webhook_id')
            ->whereNull('user_id')
            ->where('type', 'text')
            ->update(['type' => 'bot']);
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('chat_messages')
            ->whereNotNull('webhook_id')
            ->whereNull('user_id')
            ->where('type', 'bot')
            ->whereNull('system_key')
            ->update(['type' => 'text']);
    },
];
