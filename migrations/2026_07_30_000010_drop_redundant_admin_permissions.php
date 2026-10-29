<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Flarum\Group\Group;
use Illuminate\Database\Schema\Builder;

/**
 * Remove permissões concedidas ao grupo Administrator.
 *
 * Redundantes (`hasPermission()` já libera admins) e visíveis: o
 * PermissionDropdown do core sempre prefixa o badge de admin, que aparecia
 * duplicado. As migrations originais foram corrigidas; numa instalação nova
 * esta não acha nada. Ninguém perde acesso.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('group_id', Group::ADMINISTRATOR_ID)
            ->whereIn('permission', [
                'ramon-chat.createChannel',
                'ramon-chat.editChannel',
                'ramon-chat.pinMessage',
            ])
            ->delete();
    },

    'down' => function (Builder $schema) {
        // Não restaura: devolveria o badge duplicado sem devolver capacidade alguma.
    },
];
