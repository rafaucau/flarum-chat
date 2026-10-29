<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Remove `ramon-chat.default_reactions`.
 *
 * Era serializada em todo payload sem ninguém ler. Tirar só o extender deixaria
 * a linha no banco. Sem down.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()
            ->table('settings')
            ->where('key', 'ramon-chat.default_reactions')
            ->delete();
    },

    'down' => fn () => null,
];
