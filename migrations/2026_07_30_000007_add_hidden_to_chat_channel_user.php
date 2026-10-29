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
 * Associação oculta.
 *
 * Um moderador pode ler um canal sem que sua entrada mude a conversa: conta como
 * membro para ele (visibilidade, não lidas, sidebar) e fica invisível para os
 * outros (lista, `user_count`, anúncios). Fica na associação, não no usuário,
 * para ocultar num canal e participar abertamente em outro.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->after('following');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channel_user', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    },
];
