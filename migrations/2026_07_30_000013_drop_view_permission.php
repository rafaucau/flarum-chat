<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Remove `ramon-chat.view`.
 *
 * A leitura por visitantes deslogados deixou de ser desejada; uma permissão que
 * não faz nada não deve ficar na grade. Todas as concessões saem, Guest inclusive.
 * `use` continua governando o chat.
 *
 * Irreversível: o código que lia a permissão também saiu.
 */
return [
    // O migrator do Flarum passa um schema Builder; tipar como ConnectionInterface
    // dá TypeError e aborta todas as migrations da extensão.
    'up' => function (Builder $schema) {
        $schema->getConnection()
            ->table('group_permission')
            ->where('permission', 'ramon-chat.view')
            ->delete();
    },

    'down' => function (Builder $schema) {
        // Nada; ver acima.
    },
];
