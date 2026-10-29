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
 * Moderador de canal, papel dado pelo dono a um membro.
 *
 * Fica na associação porque a confiança é local ao canal. Só é lido no modo
 * "members" (Service\ChannelOwnership); voltar a "administrators" desliga os
 * papéis sem apagá-los.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_channel_user', 'is_moderator')) {
            return;
        }

        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->boolean('is_moderator')->default(false)->after('hidden');
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_channel_user', 'is_moderator')) {
            return;
        }

        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->dropColumn('is_moderator');
        });
    },
];
