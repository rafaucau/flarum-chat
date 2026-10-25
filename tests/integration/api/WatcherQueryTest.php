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
use Illuminate\Database\Events\QueryExecuted;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Watchers looked up for a notification that cannot be delivered.
 *
 * ChatMessageBlueprint has no delivery channel, so the "notify on every
 * message" branch can never send anything. It still read every watcher of the
 * channel, and of the thread, on each message before finding that out.
 */
class WatcherQueryTest extends TestCase
{
    use ResetsVisibilityScopers;
    use RetrievesAuthorizedUsers;

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
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'Lounge', 'slug' => 'lounge', 'status' => 'open', 'creator_id' => 1, 'user_count' => 2, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => 1, 'created_at' => $now],
                ['channel_id' => 1, 'user_id' => 2, 'notification_level' => 2, 'created_at' => $now],
            ],
        ]);
    }

    public function test_sending_a_message_reads_no_watchers_it_cannot_notify(): void
    {
        $watcherReads = [];

        $this->database()->listen(function (QueryExecuted $query) use (&$watcherReads) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, 'notification_level')) {
                $watcherReads[] = $query->sql;
            }
        });

        $response = $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => 1,
                'json'            => ['data' => ['attributes' => ['channelId' => 1, 'content' => 'no mentions here']]],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([], $watcherReads);
    }
}
