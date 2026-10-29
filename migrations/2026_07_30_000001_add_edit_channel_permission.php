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
 * Editar canal é um direito separado de criar, e mais estreito que `moderate`
 * (que também apaga e move mensagens alheias).
 *
 * Nada é concedido: sem dono padrão além dos admins, que passam direto. Conceder
 * ao grupo Administrator duplicaria o badge na grade (ver 2026_07_30_000010).
 */
return Migration::addPermissions([]);
