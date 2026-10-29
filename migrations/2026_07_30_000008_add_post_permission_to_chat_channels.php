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
 * Quem posta no canal.
 *
 * `all` (padrão, como os canais existentes) ou `moderators`, que restringe a quem
 * tem `ramon-chat.moderate`: um canal de anúncios.
 *
 * String em vez de boolean para comportar um terceiro estado sem nova migration.
 * Independente de `is_private`, que decide quem *vê* o canal.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->string('post_permission', 20)->default('all')->after('is_private');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('post_permission');
        });
    },
];
