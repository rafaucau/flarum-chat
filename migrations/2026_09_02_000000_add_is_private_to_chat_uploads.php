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
 * Em qual disco o anexo fica.
 *
 * False: disco público `chat`. True: `chat-private`, fora do webroot, só via
 * ServeUploadController. Upload::url() e toda exclusão leem a flag, então linha e
 * arquivo nunca divergem.
 */
return [
    // O migrator do Flarum passa um schema Builder; tipar como ConnectionInterface
    // dá TypeError e aborta todas as migrations da extensão.
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('chat_uploads', 'is_private')) {
            return;
        }

        $schema->table('chat_uploads', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('path');
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('chat_uploads', 'is_private')) {
            return;
        }

        $schema->table('chat_uploads', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    },
];
