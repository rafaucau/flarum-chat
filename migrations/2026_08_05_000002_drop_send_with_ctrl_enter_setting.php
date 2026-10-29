<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Remove o padrão de tecla de envio do fórum.
 *
 * Agora é preferência do membro (padrão Enter) e a configuração não tinha mais
 * leitor. A preferência fica: `sendKeyPreference()` lê tudo que não é "ctrl"
 * como Enter, então os "default" antigos resolvem sem reescrita.
 */
return [
    // O migrator do Flarum passa um schema Builder; tipar como ConnectionInterface
    // dá TypeError e aborta todas as migrations da extensão.
    'up' => function (Builder $schema) {
        $schema->getConnection()
            ->table('settings')
            ->where('key', 'ramon-chat.send_with_ctrl_enter')
            ->delete();
    },

    // Sem rollback: a configuração não tem mais leitor.
    'down' => fn () => null,
];
