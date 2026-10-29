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
 * Ler o chat como direito separado de participar (`ramon-chat.use`).
 *
 * Não concede a GUEST: valeria para a internet inteira. Semeia só MEMBER; quem
 * quiser leitura pública marca a coluna Guest.
 *
 * Só semeia se o grupo existir: `group_permission.group_id` é FK para `groups`
 * e inserir às cegas aborta a ativação (SQLSTATE[23000]).
 */
return [
    // O migrator do Flarum passa um schema Builder; tipar como ConnectionInterface
    // dá TypeError e aborta todas as migrations da extensão.
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        // Checa só a linha que esta migration semeia: checar qualquer concessão
        // faria um Guest já marcado pelo admin pular a semente de MEMBER.
        $exists = $db->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.view')
            ->exists();

        if ($exists) {
            return;
        }

        if ($db->table('groups')->where('id', Group::MEMBER_ID)->doesntExist()) {
            return;
        }

        // Só MEMBER: moderadores e admins herdam pelos grupos, e semeá-los duplica
        // o badge na grade.
        $db->table('group_permission')->insert([
            ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.view'],
        ]);
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();

        // Só o que `up` criou; não leva junto um Guest concedido pelo admin.
        $db->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.view')
            ->delete();
    },
];
