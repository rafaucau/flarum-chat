<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * A channel open to everyone, IRC style.
 *
 * Reported from a forum with 400k accounts: "Automatically add all users" wrote
 * a membership row for each of them inside the request that created the
 * channel, and the forum crawled. Everyone who can see such a channel is now in
 * it without a row of their own — a row appears only once they do something
 * that needs one, such as reading the channel or leaving it.
 */
class EveryoneChannelTest extends TestCase
{
    use ResetsVisibilityScopers;
    use RetrievesAuthorizedUsers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    protected function allowedRepeatedQueries(): array
    {
        return ['fof_upload_files'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'second', 'password' => self::PASSWORD_HASH, 'email' => 'second@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'pending', 'password' => self::PASSWORD_HASH, 'email' => 'pending@machine.local', 'is_email_confirmed' => 0],
            ],
        ]);
    }

    private function createChannel(bool $everyone): int
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => 1,
                'json'            => ['data' => ['attributes' => [
                    'name'     => 'General',
                    'type'     => 'category',
                    'autoJoin' => $everyone,
                ]]],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function channelAs(int $channelId, int $as): array
    {
        $response = $this->send(
            $this->request('GET', '/api/chat-channels/'.$channelId, ['authenticatedAs' => $as])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data']['attributes'];
    }

    /**
     * @return int[]
     */
    private function followedBy(int $as, bool $following = true): array
    {
        $response = $this->send(
            $this->request('GET', '/api/chat-channels', ['authenticatedAs' => $as])
                ->withQueryParams(['filter' => ['following' => $following ? '1' : '0']])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        return array_map(
            static fn (array $row) => (int) $row['id'],
            json_decode((string) $response->getBody(), true)['data']
        );
    }

    /**
     * @return int[]
     */
    private function rows(int $channelId): array
    {
        return $this->database()->table('chat_channel_user')
            ->where('channel_id', $channelId)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    public function test_creating_it_writes_no_row_for_anyone_but_its_creator(): void
    {
        $channelId = $this->createChannel(true);

        $this->assertSame([1], $this->rows($channelId));
    }

    public function test_everyone_who_can_see_it_follows_it_and_can_post_without_joining(): void
    {
        $channelId = $this->createChannel(true);

        $attributes = $this->channelAs($channelId, 2);

        $this->assertTrue($attributes['isFollowing']);
        $this->assertTrue($attributes['canPostMessage']);
        $this->assertContains($channelId, $this->followedBy(2));
        $this->assertNotContains($channelId, $this->followedBy(2, false));

        $response = $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => 2,
                'json'            => ['data' => ['attributes' => [
                    'channelId' => $channelId,
                    'content'   => 'hello everyone',
                ]]],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_turning_it_on_later_includes_everyone_at_once(): void
    {
        $channelId = $this->createChannel(false);

        $this->assertNotContains($channelId, $this->followedBy(3));

        $response = $this->send(
            $this->request('PATCH', '/api/chat-channels/'.$channelId, [
                'authenticatedAs' => 1,
                'json'            => ['data' => ['attributes' => ['autoJoin' => true]]],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertContains($channelId, $this->followedBy(3));
        $this->assertTrue($this->channelAs($channelId, 3)['isFollowing']);
        $this->assertSame([1], $this->rows($channelId));
    }

    public function test_an_account_confirmed_after_the_channel_was_created_is_in_it(): void
    {
        $channelId = $this->createChannel(true);

        $this->database()->table('users')->where('id', 4)->update(['is_email_confirmed' => 1]);

        $this->assertContains($channelId, $this->followedBy(4));
        $this->assertTrue($this->channelAs($channelId, 4)['canPostMessage']);
    }

    public function test_leaving_takes_it_out_of_the_sidebar_until_rejoined(): void
    {
        $channelId = $this->createChannel(true);

        $leave = $this->send(
            $this->request('POST', '/api/chat-channels/'.$channelId.'/leave', ['authenticatedAs' => 2])
        );

        $this->assertEquals(204, $leave->getStatusCode(), (string) $leave->getBody());
        $this->assertNotContains($channelId, $this->followedBy(2));
        $this->assertContains($channelId, $this->followedBy(2, false));

        $attributes = $this->channelAs($channelId, 2);

        $this->assertFalse($attributes['isFollowing']);
        $this->assertFalse($attributes['canPostMessage']);
        $this->assertTrue($attributes['canJoin']);

        $join = $this->send(
            $this->request('POST', '/api/chat-channels/'.$channelId.'/join', ['authenticatedAs' => 2])
        );

        $this->assertEquals(200, $join->getStatusCode(), (string) $join->getBody());
        $this->assertContains($channelId, $this->followedBy(2));
    }

    public function test_reading_it_starts_a_row_so_the_read_marker_has_somewhere_to_live(): void
    {
        $channelId = $this->createChannel(true);

        $read = $this->send(
            $this->request('POST', '/api/chat-channels/'.$channelId.'/read', ['authenticatedAs' => 3])
        );

        $this->assertEquals(204, $read->getStatusCode(), (string) $read->getBody());
        $this->assertSame([1, 3], $this->rows($channelId));
    }

    private function post(int $channelId, int $as, string $content): int
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => [
                    'channelId' => $channelId,
                    'content'   => $content,
                ]]],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }

    private function read(int $channelId, int $as): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels/'.$channelId.'/read', ['authenticatedAs' => $as])
        );

        $this->assertEquals(204, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     */
    private function userAs(int $as): array
    {
        $response = $this->send($this->request('GET', '/api/users/'.$as, ['authenticatedAs' => $as]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data']['attributes'];
    }

    public function test_a_message_writes_no_counter_and_unread_is_counted_when_read(): void
    {
        $channelId = $this->createChannel(true);

        $this->read($channelId, 2);
        $this->post($channelId, 3, 'one');
        $this->post($channelId, 3, 'two');

        // Nothing per member is written on send. Incrementing a counter on every
        // row was one UPDATE across the whole channel for each message.
        $this->assertSame(
            0,
            (int) $this->database()->table('chat_channel_user')
                ->where('channel_id', $channelId)
                ->where('user_id', 2)
                ->value('unread_count')
        );

        $this->assertSame(2, $this->channelAs($channelId, 2)['unreadCount']);
        $this->assertSame(2, $this->userAs(2)['chatUnreadMessagesCount']);
        $this->assertSame(1, $this->userAs(2)['chatUnreadChannelsCount']);

        $this->read($channelId, 2);

        $this->assertSame(0, $this->channelAs($channelId, 2)['unreadCount']);
        $this->assertSame(0, $this->userAs(2)['chatUnreadMessagesCount']);
    }

    public function test_a_deleted_message_stops_counting_as_unread(): void
    {
        $channelId = $this->createChannel(true);

        $this->read($channelId, 2);
        $this->post($channelId, 3, 'kept');
        $removed = $this->post($channelId, 3, 'removed');

        $response = $this->send(
            $this->request('POST', '/api/chat-messages/'.$removed.'/delete', ['authenticatedAs' => 3])
        );

        $this->assertLessThan(300, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, $this->channelAs($channelId, 2)['unreadCount']);
    }

    public function test_muting_it_silences_the_badge(): void
    {
        $channelId = $this->createChannel(true);

        $this->read($channelId, 2);
        $this->post($channelId, 3, 'noise');

        $response = $this->send(
            $this->request('POST', '/api/chat-channels/'.$channelId.'/notifications', [
                'authenticatedAs' => 2,
                'json'            => ['data' => ['attributes' => ['muted' => true]]],
            ])
        );

        $this->assertEquals(204, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, $this->channelAs($channelId, 2)['unreadCount']);
        $this->assertSame(0, $this->userAs(2)['chatUnreadMessagesCount']);
    }

    /**
     * @return int[]
     */
    private function listedMembers(int $channelId, int $as): array
    {
        $response = $this->send(
            $this->request('GET', '/api/chat-channels/'.$channelId, ['authenticatedAs' => $as])
                ->withQueryParams(['include' => 'participants'])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $ids = array_map(
            static fn (array $ref) => (int) $ref['id'],
            json_decode((string) $response->getBody(), true)['data']['relationships']['participants']['data'] ?? []
        );

        sort($ids);

        return $ids;
    }

    public function test_its_member_list_shows_only_who_runs_it(): void
    {
        $channelId = $this->createChannel(true);

        $this->read($channelId, 2);
        $this->read($channelId, 3);

        $this->database()->table('chat_channel_user')
            ->where('channel_id', $channelId)
            ->where('user_id', 3)
            ->update(['is_moderator' => 1]);

        // The creator and the channel's moderators. Listing everyone who has
        // read a general channel is a list of the forum, and serialising it was
        // what kept the members tab from ever loading.
        $this->assertSame([1, 3], $this->listedMembers($channelId, 2));
    }

    public function test_an_ordinary_channel_still_lists_every_member(): void
    {
        $channelId = $this->createChannel(false);

        $join = $this->send(
            $this->request('POST', '/api/chat-channels/'.$channelId.'/join', ['authenticatedAs' => 2])
        );

        $this->assertEquals(200, $join->getStatusCode(), (string) $join->getBody());
        $this->assertSame([1, 2], $this->listedMembers($channelId, 2));
    }

    public function test_a_mention_of_the_whole_channel_is_not_offered_in_it(): void
    {
        $channelId = $this->createChannel(true);

        // Administrators hold every permission, `mentionChannelWide` included.
        $this->assertFalse($this->channelAs($channelId, 1)['canMentionChannelWide']);

        $messageId = $this->post($channelId, 1, '@all the forum is moving tonight');

        // In a channel for everyone, @all is the whole forum: one message would
        // notify and badge every account.
        $this->assertSame(
            0,
            $this->database()->table('chat_message_mentions')
                ->where('message_id', $messageId)
                ->whereIn('type', ['all', 'here'])
                ->count()
        );
    }

    public function test_a_whole_channel_mention_is_counted_without_listing_every_member(): void
    {
        $channelId = $this->createChannel(false);

        foreach ([2, 3] as $as) {
            $join = $this->send(
                $this->request('POST', '/api/chat-channels/'.$channelId.'/join', ['authenticatedAs' => $as])
            );

            $this->assertEquals(200, $join->getStatusCode(), (string) $join->getBody());
        }

        $listed = [];

        $this->database()->listen(function ($query) use (&$listed) {
            if (str_contains($query->sql, 'unread_mentions_count') && str_contains($query->sql, '"user_id" in (')) {
                $listed[] = $query->sql;
            }
        });

        $this->post($channelId, 1, '@all heads up');

        // One placeholder per member broke the send past 65,535 members.
        $this->assertSame([], $listed);

        $counts = $this->database()->table('chat_channel_user')
            ->where('channel_id', $channelId)
            ->whereIn('user_id', [2, 3])
            ->pluck('unread_mentions_count')
            ->map(static fn ($count) => (int) $count)
            ->all();

        $this->assertSame([1, 1], $counts);
    }

    public function test_someone_who_never_opened_it_has_nothing_unread(): void
    {
        $channelId = $this->createChannel(true);

        $this->post($channelId, 3, 'before you came');

        $this->assertSame(0, $this->channelAs($channelId, 2)['unreadCount']);
        $this->assertSame(0, $this->userAs(2)['chatUnreadMessagesCount']);
    }
}
