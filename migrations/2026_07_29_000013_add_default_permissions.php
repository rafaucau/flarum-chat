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
 * Padrões: participar é para membros, moderar não.
 *
 * Entrar, abrir conversa direta, anexar e reagir vão para MEMBER, que todo
 * usuário registrado tem (cobre moderadores). Só a menção ao canal todo e a
 * moderação ficam com MODERATOR. Admin não recebe linha: `hasPermission()` já
 * retorna true e a linha duplica o badge na grade.
 *
 * `Migration::addPermissions` ignora grupos inexistentes.
 */
return Migration::addPermissions([
    'ramon-chat.use'                 => Group::MEMBER_ID,
    'ramon-chat.startDirect'         => Group::MEMBER_ID,
    'ramon-chat.upload'              => Group::MEMBER_ID,
    'ramon-chat.react'               => Group::MEMBER_ID,
    'ramon-chat.mentionChannelWide'  => Group::MODERATOR_ID,
    'ramon-chat.moderate'            => Group::MODERATOR_ID,
]);
