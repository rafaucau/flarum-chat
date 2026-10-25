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
use Illuminate\Database\Events\QueryExecuted;
use Pusher\Pusher;
use Ramon\Chat\Channel;
use Ramon\Chat\Realtime\BroadcastListener;
use Ramon\Chat\Realtime\ChatBroadcaster;
use Ramon\Chat\Realtime\Job\SendChatEventJob;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * A push to a channel larger than one batch of its audience.
 *
 * The job loaded every member's user and groups in one query before sending
 * anything, inside the request that sent the message. In a general channel on
 * a large forum that was hundreds of thousands of models, and the request died
 * of memory with the message already saved.
 */
class RealtimeLargeAudienceTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const MEMBERS = SendChatEventJob::AUDIENCE_BATCH * 2 + 37;

    private const FIRST_ID = 1000;

    /** The stand-in daemon, with every trigger it was handed. */
    private ?object $recorder = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'General', 'slug' => 'general', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    private function seedMembers(): void
    {
        $now = Carbon::now()->toDateTimeString();

        $users = [];
        $memberships = [];

        for ($i = 0; $i < self::MEMBERS; $i++) {
            $id = self::FIRST_ID + $i;

            $users[] = ['id' => $id, 'username' => 'member'.$id, 'email' => 'member'.$id.'@machine.local', 'password' => 'x', 'is_email_confirmed' => 1, 'joined_at' => $now];
            $memberships[] = ['channel_id' => 1, 'user_id' => $id, 'created_at' => $now];
        }

        foreach (array_chunk($users, 100) as $chunk) {
            $this->database()->table('users')->insert($chunk);
        }

        foreach (array_chunk($memberships, 100) as $chunk) {
            $this->database()->table('chat_channel_user')->insert($chunk);
        }
    }

    private function bindRecorder(): void
    {
        $recorder = new class('key', 'secret', '1') extends Pusher {
            /** @var array<int, mixed> */
            public array $calls = [];

            public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
            {
                $this->calls[] = ['channels' => (array) $channels, 'event' => $event];

                return new \stdClass();
            }
        };

        $this->app()->getContainer()->instance(Pusher::class, $recorder);

        $this->recorder = $recorder;
    }

    public function test_every_member_is_reached_without_loading_them_all_at_once(): void
    {
        $this->bindRecorder();
        $this->seedMembers();

        $largestUserLoad = 0;

        $this->database()->listen(function (QueryExecuted $query) use (&$largestUserLoad) {
            if (preg_match('/from "users" where "users"\."id" in \(([^)]*)\)/', $query->sql, $match)) {
                $largestUserLoad = max($largestUserLoad, count(explode(',', $match[1])));
            }
        });

        $this->app()->getContainer()->make(ChatBroadcaster::class)->toChannelMembers(
            Channel::query()->findOrFail(1),
            BroadcastListener::EVENT_MESSAGE,
            ['id' => 1],
            null
        );

        $reached = [];

        foreach ($this->recorder->calls as $call) {
            $this->assertLessThanOrEqual(100, count($call['channels']), 'the daemon takes 100 channels per trigger');

            foreach ($call['channels'] as $channel) {
                $reached[$channel] = true;
            }
        }

        $this->assertCount(self::MEMBERS, $reached, 'every member got the push, once');
        $this->assertGreaterThan(0, $largestUserLoad, 'the audience was read from users');
        $this->assertLessThanOrEqual(SendChatEventJob::AUDIENCE_BATCH, $largestUserLoad);
    }
}
