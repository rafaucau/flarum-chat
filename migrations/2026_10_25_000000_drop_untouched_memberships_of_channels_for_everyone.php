<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Drops the memberships "add all users" wrote that nobody ever used.
 *
 * A channel for everyone no longer needs a row per member: whoever can see it
 * is in it, and a row is written once there is something of theirs to keep.
 * Forums that turned the setting on before still hold one row per account,
 * and every push to the channel walks each of them. A row that was never read,
 * never configured, holds no role and is not the creator's carries nothing its
 * owner would lose, and removing it leaves them in the channel as before.
 *
 * Deleted by id in batches, so a forum of hundreds of thousands of accounts
 * runs bounded statements, and safe to run again. There is no `down`: the
 * rows held nothing to restore.
 */
return [
    'up' => function (Builder $schema) {
        // Flarum's Migrator hands a closure migration the schema builder and
        // nothing else, so the connection is reached through it.
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

            // By id rather than by offset, so deleting a batch does not shift the
            // next one past rows it should have seen.
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
