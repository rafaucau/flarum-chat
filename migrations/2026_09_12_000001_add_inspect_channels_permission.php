<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Group\Group;
use Illuminate\Database\Schema\Builder;

/**
 * Inspecionar canal sem ser notado como direito próprio, antes embutido em
 * `ramon-chat.moderate`.
 *
 * Semeado para todo grupo que já tem `moderate`, para nenhum moderador perder a
 * capacidade no upgrade. Admins não precisam de linha.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $groupIds = $db->table('group_permission')
            ->where('permission', 'ramon-chat.moderate')
            ->where('group_id', '!=', Group::ADMINISTRATOR_ID)
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        foreach ($groupIds as $groupId) {
            $seeded = $db->table('group_permission')
                ->where('group_id', $groupId)
                ->where('permission', 'ramon-chat.inspectChannels')
                ->exists();

            if ($seeded) {
                continue;
            }

            if ($db->table('groups')->where('id', $groupId)->doesntExist()) {
                continue;
            }

            $db->table('group_permission')->insert([
                ['group_id' => $groupId, 'permission' => 'ramon-chat.inspectChannels'],
            ]);
        }
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('permission', 'ramon-chat.inspectChannels')
            ->delete();
    },
];
