<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Canais de categoria públicos e privados.
 *
 * Público aparece no Browse para quem a categoria admite e pode entrar sozinho.
 * Privado é só por convite: visível apenas a membros, fora do Browse.
 *
 * Soma-se ao vínculo com a tag. Canais diretos nunca têm a flag. Padrão false
 * para os canais existentes não mudarem.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('status');
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('chat_channels', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    },
];
