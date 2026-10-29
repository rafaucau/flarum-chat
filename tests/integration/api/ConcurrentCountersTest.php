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
use Flarum\User\User;
use Ramon\Chat\Channel;
use Ramon\Chat\Service\MessageDispatcher;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;
use Ramon\Chat\Thread;

/**
 * Contadores de canal e de thread sob envios simultâneos.
 *
 * Cada request carrega o canal antes da transação. Gravar `messages_count + 1`
 * calculado em PHP faz dois envios ao mesmo tempo contarem um só, e a última
 * mensagem pode voltar para a mais antiga. Aqui a concorrência vira duas cópias
 * do mesmo canal, cada uma lida antes do outro envio.
 */
class ConcurrentCountersTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const ALICE = 3;
    private const BOB = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();
        $user = fn (int $id, string $name) => ['id' => $id, 'username' => $name, 'email' => $name.'@machine.local', 'password' => 'x', 'is_email_confirmed' => 1];

        $this->prepareDatabase([
            'users' => [$user(self::ALICE, 'alice'), $user(self::BOB, 'bob')],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'Geral', 'slug' => 'geral', 'status' => 'open', 'threading_enabled' => 1, 'messages_count' => 1, 'last_message_id' => 1, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => self::ALICE, 'created_at' => $now],
                ['channel_id' => 1, 'user_id' => self::BOB, 'created_at' => $now],
            ],
            'chat_messages' => [
                ['id' => 1, 'channel_id' => 1, 'user_id' => self::ALICE, 'number' => 1, 'thread_id' => 7, 'type' => 'text', 'content' => '<t><p>raiz</p></t>', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_threads' => [
                ['id' => 7, 'channel_id' => 1, 'original_message_id' => 1, 'creator_id' => self::ALICE, 'status' => 'open', 'replies_count' => 0, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    private function dispatcher(): MessageDispatcher
    {
        return $this->app()->getContainer()->make(MessageDispatcher::class);
    }

    public function test_two_sends_from_stale_copies_both_count(): void
    {
        $this->app();

        $first = Channel::query()->findOrFail(1);
        $second = Channel::query()->findOrFail(1);

        $this->dispatcher()->send($first, User::query()->findOrFail(self::ALICE), 'um');
        $latest = $this->dispatcher()->send($second, User::query()->findOrFail(self::BOB), 'dois');

        $row = $this->database()->table('chat_channels')->where('id', 1)->first();

        $this->assertSame(3, (int) $row->messages_count);
        $this->assertSame((int) $latest->id, (int) $row->last_message_id);
    }

    public function test_a_late_commit_does_not_move_the_last_message_back(): void
    {
        $this->app();

        $stale = Channel::query()->findOrFail(1);

        // Outro envio já registrou uma mensagem mais nova enquanto este esperava.
        $now = Carbon::now()->toDateTimeString();
        $this->database()->table('chat_messages')->insert(['id' => 500, 'channel_id' => 1, 'user_id' => self::BOB, 'number' => 500, 'type' => 'text', 'content' => '<t><p>nova</p></t>', 'created_at' => $now, 'updated_at' => $now]);
        $this->database()->table('chat_channels')->where('id', 1)->update(['last_message_id' => 500, 'messages_count' => 2]);

        $sent = $this->dispatcher()->send($stale, User::query()->findOrFail(self::ALICE), 'atrasada');

        $this->assertSame(max(500, (int) $sent->id), (int) $this->database()->table('chat_channels')->where('id', 1)->value('last_message_id'));
        $this->assertSame(3, (int) $this->database()->table('chat_channels')->where('id', 1)->value('messages_count'));
    }

    public function test_two_replies_from_stale_thread_copies_both_count(): void
    {
        $this->app();

        $channel = Channel::query()->findOrFail(1);

        $first = Thread::query()->findOrFail(7);
        $second = Thread::query()->findOrFail(7);

        $this->dispatcher()->send($channel, User::query()->findOrFail(self::ALICE), 'r1', $first);
        $this->dispatcher()->send(Channel::query()->findOrFail(1), User::query()->findOrFail(self::BOB), 'r2', $second);

        $this->assertSame(2, (int) $this->database()->table('chat_threads')->where('id', 7)->value('replies_count'));
    }
}
