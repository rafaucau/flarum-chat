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
 * Quem cria e gerencia canais vira um switch (`ramon-chat.channel_ownership`)
 * mais três permissões: criar, gerenciar e editar os próprios canais.
 *
 *  1. Fórum que já concedia `createChannel` a um grupo não admin vai para o modo
 *     "members"; aplicar o padrão "administrators" revogaria isso no upgrade.
 *  2. As três permissões são semeadas a Member. Inertes no modo
 *     "administrators", então basta virar o switch.
 *
 * Só semeia se o grupo existir e a linha ainda não: `group_permission.group_id`
 * é FK para `groups` e inserir às cegas aborta a ativação (SQLSTATE[23000]).
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $settingExists = $db->table('settings')
            ->where('key', 'ramon-chat.channel_ownership')
            ->exists();

        $alreadyDelegated = $db->table('group_permission')
            ->where('permission', 'ramon-chat.createChannel')
            ->where('group_id', '!=', Group::ADMINISTRATOR_ID)
            ->exists();

        if (! $settingExists && $alreadyDelegated) {
            $db->table('settings')->insert([
                'key'   => 'ramon-chat.channel_ownership',
                'value' => 'members',
            ]);
        }

        if ($db->table('groups')->where('id', Group::MEMBER_ID)->doesntExist()) {
            return;
        }

        $permissions = [
            'ramon-chat.createChannel',
            'ramon-chat.manageOwnChannels',
            'ramon-chat.editOwnChannels',
        ];

        foreach ($permissions as $permission) {
            $seeded = $db->table('group_permission')
                ->where('group_id', Group::MEMBER_ID)
                ->where('permission', $permission)
                ->exists();

            if ($seeded) {
                continue;
            }

            $db->table('group_permission')->insert([
                ['group_id' => Group::MEMBER_ID, 'permission' => $permission],
            ]);
        }
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();

        $db->table('settings')
            ->where('key', 'ramon-chat.channel_ownership')
            ->delete();

        $db->table('group_permission')
            ->whereIn('permission', ['ramon-chat.manageOwnChannels', 'ramon-chat.editOwnChannels'])
            ->delete();

        $db->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.createChannel')
            ->delete();
    },
];
