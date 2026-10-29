<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Cache\Repository;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Cada denúncia, ou a reabertura de uma já resolvida, avisa a moderação. Sem
 * limite, quem denunciou desfaria a decisão quantas vezes quisesse.
 */
class FlagLimitsTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const REPORTER = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                ['id' => self::REPORTER, 'username' => 'reporter', 'email' => 'reporter@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.flagMessage'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'geral', 'slug' => 'geral', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_messages' => [
                ['id' => 1, 'channel_id' => 1, 'user_id' => 1, 'number' => 1, 'type' => 'text', 'content' => '<t>oi</t>', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    public function test_reopening_a_resolved_report_is_throttled(): void
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        $statuses = [];

        for ($i = 0; $i < 21; $i++) {
            // A moderação resolve, e quem denunciou reabre.
            $this->database()->table('chat_message_flags')->update(['resolved_at' => Carbon::now()->toDateTimeString(), 'resolved_by_id' => 1]);

            $statuses[] = $this->send($this->request('POST', '/api/chat-message-flags', [
                'authenticatedAs' => self::REPORTER,
                'json'            => ['data' => ['type' => 'chat-message-flags', 'attributes' => ['messageId' => 1, 'reason' => 'spam']]],
            ]))->getStatusCode();
        }

        $this->assertSame(201, $statuses[0]);
        $this->assertSame(422, $statuses[20]);
    }
}
