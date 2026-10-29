<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Flarum\Group\Group;

/**
 * Criar thread é um direito próprio, porque muda como o canal é lido por todos.
 *
 * Concedido a membros, que já podiam fazê-lo; nada é retirado, só passa a ser
 * revogável. Admins passam por User::hasPermission().
 */
return Migration::addPermissions([
    'ramon-chat.createThread' => Group::MEMBER_ID,
]);
