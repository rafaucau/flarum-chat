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
 * Ignorar o slow mode como direito próprio, separado de `ramon-chat.moderate`
 * (ex.: bot ou atendente sem poder de moderação).
 *
 * Semeado a MODERATOR para nada mudar em quem já usava slow mode; admins passam
 * direto sem linha.
 *
 * Só semeia se o grupo existir: `group_permission.group_id` é FK para `groups`
 * e inserir às cegas aborta a ativação (SQLSTATE[23000]).
 */
return [
    // O migrator do Flarum passa um schema Builder; tipar como ConnectionInterface
    // dá TypeError e aborta todas as migrations da extensão.
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        $exists = $db->table('group_permission')
            ->where('group_id', Group::MODERATOR_ID)
            ->where('permission', 'ramon-chat.bypassSlowMode')
            ->exists();

        if ($exists) {
            return;
        }

        if ($db->table('groups')->where('id', Group::MODERATOR_ID)->doesntExist()) {
            return;
        }

        $db->table('group_permission')->insert([
            ['group_id' => Group::MODERATOR_ID, 'permission' => 'ramon-chat.bypassSlowMode'],
        ]);
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('permission', 'ramon-chat.bypassSlowMode')
            ->delete();
    },
];
