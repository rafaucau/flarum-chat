<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Apaga associações criadas por "adicionar todos" que nunca foram usadas.
 *
 * Canal para todos não precisa mais de uma linha por membro, e cada push
 * percorria todas. Linha nunca lida nem configurada, sem papel e que não é do
 * criador não carrega nada; o usuário continua no canal.
 *
 * Em lotes por id, com statements limitados, e pode rodar de novo. Sem `down`.
 */
return [
    'up' => function (Builder $schema) {
        // O Migrator só entrega o schema builder; a conexão vem dele.
        $db = $schema->getConnection();

        $channels = $db->table('chat_channels')
            ->where('type', 'category')
            ->where('auto_join', true)
            ->where('is_private', false)
            ->get(['id', 'creator_id']);

        foreach ($channels as $channel) {
            $untouched = $db->table('chat_channel_user')
                ->where('chat_channel_user.channel_id', $channel->id)
                ->whereNull('chat_channel_user.left_at')
                ->whereNull('chat_channel_user.last_read_message_id')
                ->where('chat_channel_user.following', true)
                ->where('chat_channel_user.hidden', false)
                ->where('chat_channel_user.is_moderator', false)
                ->where('chat_channel_user.muted', false)
                ->where('chat_channel_user.notification_level', 1)
                ->where('chat_channel_user.unread_mentions_count', 0)
                ->when($channel->creator_id !== null, fn ($query) => $query->where('chat_channel_user.user_id', '!=', $channel->creator_id))
                ->whereNotExists(function ($sub) {
                    $sub->selectRaw(1)
                        ->from('chat_channel_rank_user')
                        ->whereColumn('chat_channel_rank_user.channel_id', 'chat_channel_user.channel_id')
                        ->whereColumn('chat_channel_rank_user.user_id', 'chat_channel_user.user_id');
                })
                ->select('chat_channel_user.id');

            // Por id, não offset, para apagar um lote não deslocar o próximo.
            $untouched->chunkById(1000, function ($rows) use ($db) {
                $db->table('chat_channel_user')
                    ->whereIn('id', $rows->pluck('id')->all())
                    ->delete();
            }, 'chat_channel_user.id', 'id');

            $db->table('chat_channels')->where('id', $channel->id)->update([
                'user_count' => $db->table('chat_channel_user')
                    ->where('channel_id', $channel->id)
                    ->whereNull('left_at')
                    ->where('hidden', false)
                    ->count(),
            ]);
        }
    },

    'down' => function () {
    },
];
