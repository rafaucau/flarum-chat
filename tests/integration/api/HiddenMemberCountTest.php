<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * A member count that gives away who is watching.
 *
 * Joining hidden keeps a moderator out of the member list and out of
 * `user_count`. Any recount — and a member deleting their own message is one —
 * counted every live membership, hidden ones included, so the number on the
 * channel card rose above the list by exactly the number of observers.
 */
class HiddenMemberCountTest extends TestCase
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

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'observer', 'password' => self::PASSWORD_HASH, 'email' => 'observer@machine.local', 'is_email_confirmed' => 1],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'Lounge', 'slug' => 'lounge', 'status' => 'open', 'creator_id' => 1, 'user_count' => 2, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => 1, 'created_at' => $now],
                ['channel_id' => 1, 'user_id' => 2, 'created_at' => $now],
                ['channel_id' => 1, 'user_id' => 3, 'hidden' => 1, 'created_at' => $now],
            ],
        ]);
    }

    public function test_a_recount_leaves_hidden_members_out(): void
    {
        $sent = $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => 2,
                'json'            => ['data' => ['attributes' => ['channelId' => 1, 'content' => 'oops']]],
            ])
        );

        $this->assertEquals(201, $sent->getStatusCode(), (string) $sent->getBody());

        $messageId = (int) json_decode((string) $sent->getBody(), true)['data']['id'];

        $deleted = $this->send(
            $this->request('POST', '/api/chat-messages/'.$messageId.'/delete', ['authenticatedAs' => 2])
        );

        $this->assertLessThan(300, $deleted->getStatusCode(), (string) $deleted->getBody());

        $channel = $this->send($this->request('GET', '/api/chat-channels/1', ['authenticatedAs' => 2]));

        $this->assertEquals(200, $channel->getStatusCode(), (string) $channel->getBody());
        $this->assertSame(2, json_decode((string) $channel->getBody(), true)['data']['attributes']['userCount']);
    }
}
