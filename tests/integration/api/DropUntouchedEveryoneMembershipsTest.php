<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;

/**
 * The repair migration for the rows "add all users" wrote before a channel for
 * everyone stopped needing one per member.
 *
 * Driven by invoking the migration's `up` directly, like
 * PurgeIneligibleMembershipsTest: the harness migrates during setup, before
 * there is any old state to find.
 */
class DropUntouchedEveryoneMembershipsTest extends TestCase
{
    private const EVERYONE = 1;
    private const ORDINARY = 2;
    private const PRIVATE = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $users = [];

        foreach (range(2, 8) as $id) {
            $users[] = ['id' => $id, 'username' => 'user'.$id, 'email' => 'user'.$id.'@machine.local', 'password' => 'x', 'is_email_confirmed' => 1];
        }

        $this->prepareDatabase([
            'users'         => $users,
            'chat_channels' => [
                $this->channel(self::EVERYONE, autoJoin: true, private: false),
                $this->channel(self::ORDINARY, autoJoin: false, private: false),
                $this->channel(self::PRIVATE, autoJoin: true, private: true),
            ],
            'chat_channel_ranks' => [
                ['id' => 1, 'channel_id' => self::EVERYONE, 'name' => 'Regular', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_rank_user' => [
                ['rank_id' => 1, 'user_id' => 8, 'channel_id' => self::EVERYONE, 'created_at' => $now],
            ],
            'chat_channel_user' => [
                // The creator, whose row is the channel's ownership.
                $this->membership(self::EVERYONE, 1),
                // Never touched: the rows the old setting wrote for everyone.
                $this->membership(self::EVERYONE, 2),
                $this->membership(self::EVERYONE, 3, ['unread_count' => 40]),
                // Each of these holds something its owner would lose.
                $this->membership(self::EVERYONE, 4, ['last_read_message_id' => 10]),
                $this->membership(self::EVERYONE, 5, ['muted' => 1]),
                $this->membership(self::EVERYONE, 6, ['following' => 0, 'left_at' => $now]),
                $this->membership(self::EVERYONE, 7, ['is_moderator' => 1]),
                $this->membership(self::EVERYONE, 8),
                // Untouched, but in channels that still need their rows.
                $this->membership(self::ORDINARY, 2),
                $this->membership(self::PRIVATE, 2),
            ],
        ]);
    }

    private function channel(int $id, bool $autoJoin, bool $private): array
    {
        return [
            'id'         => $id,
            'type'       => 'category',
            'name'       => 'channel '.$id,
            'slug'       => 'channel-'.$id,
            'status'     => 'open',
            'auto_join'  => $autoJoin,
            'is_private' => $private,
            'creator_id' => 1,
            'user_count' => 99,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    private function membership(int $channelId, int $userId, array $overrides = []): array
    {
        return array_merge([
            'channel_id'         => $channelId,
            'user_id'            => $userId,
            'following'          => 1,
            'muted'              => 0,
            'notification_level' => 1,
            'created_at'         => Carbon::now()->toDateTimeString(),
            'updated_at'         => Carbon::now()->toDateTimeString(),
        ], $overrides);
    }

    private function migrate(): void
    {
        $migration = require __DIR__.'/../../../migrations/2026_10_25_000000_drop_untouched_memberships_of_channels_for_everyone.php';

        $migration['up']($this->database()->getSchemaBuilder());
    }

    /** @return int[] */
    private function memberIds(int $channelId): array
    {
        return $this->database()->table('chat_channel_user')
            ->where('channel_id', $channelId)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    public function test_it_drops_only_the_rows_nobody_used(): void
    {
        $this->migrate();

        $this->assertSame([1, 4, 5, 6, 7, 8], $this->memberIds(self::EVERYONE));
    }

    public function test_it_leaves_channels_that_still_need_their_rows_alone(): void
    {
        $this->migrate();

        $this->assertSame([2], $this->memberIds(self::ORDINARY));
        $this->assertSame([2], $this->memberIds(self::PRIVATE));
    }

    public function test_it_recounts_the_visible_members(): void
    {
        $this->migrate();

        // 1, 4, 5, 7 and 8 are live and visible; 6 has left.
        $this->assertSame(
            5,
            (int) $this->database()->table('chat_channels')->where('id', self::EVERYONE)->value('user_count')
        );
        $this->assertSame(
            99,
            (int) $this->database()->table('chat_channels')->where('id', self::ORDINARY)->value('user_count')
        );
    }

    public function test_running_it_twice_changes_nothing_further(): void
    {
        $this->migrate();
        $this->migrate();

        $this->assertSame([1, 4, 5, 6, 7, 8], $this->memberIds(self::EVERYONE));
    }
}
