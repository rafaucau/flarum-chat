<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Limpa marcas de arquivamento de canais que não estão mais arquivados.
 *
 * O endpoint de status reabria canais arquivados sem limpar `archived_at`,
 * `archived_by_id` e `archived_discussion_id`, e o canal seguia parecendo
 * arquivado. Isto corrige as linhas afetadas. Idempotente.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('chat_channels')) {
            return;
        }

        $schema->getConnection()->table('chat_channels')
            ->where('status', '!=', 'archived')
            ->where(fn ($query) => $query
                ->whereNotNull('archived_at')
                ->orWhereNotNull('archived_by_id')
                ->orWhereNotNull('archived_discussion_id'))
            ->update([
                'archived_at'            => null,
                'archived_by_id'         => null,
                'archived_discussion_id' => null,
            ]);
    },

    // Nada a restaurar: o arquivamento já tinha sido desfeito.
    'down' => function (Builder $schema) {
    },
];
