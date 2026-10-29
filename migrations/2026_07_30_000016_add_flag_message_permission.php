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
 * Denunciar mensagem como direito próprio.
 *
 * Semeado a MEMBER, mas revogável para lidar com quem denuncia de má-fé sem
 * silenciá-lo em tudo. *Ler* a fila é `ramon-chat.moderate`.
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
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.flagMessage')
            ->exists();

        if ($exists) {
            return;
        }

        if ($db->table('groups')->where('id', Group::MEMBER_ID)->doesntExist()) {
            return;
        }

        $db->table('group_permission')->insert([
            ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.flagMessage'],
        ]);
    },

    'down' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('permission', 'ramon-chat.flagMessage')
            ->delete();
    },
];
