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
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * O acompanhamento de uma thread é de cada leitor.
 *
 * O resource fica vivo entre requests no mesmo processo (worker de fila,
 * servidor persistente, a suíte de testes). Um memo guardado só pelo id da
 * thread entregava a membership do primeiro leitor a quem viesse depois.
 */
class ThreadMembershipPerActorTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const FOLLOWER = 3;
    private const STRANGER = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();
        $user = fn (int $id, string $name) => ['id' => $id, 'username' => $name, 'email' => $name.'@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1];

        $this->prepareDatabase([
            'users' => [$user(self::FOLLOWER, 'follower'), $user(self::STRANGER, 'stranger')],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'Geral', 'slug' => 'geral', 'status' => 'open', 'threading_enabled' => 1, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => self::FOLLOWER, 'created_at' => $now],
                ['channel_id' => 1, 'user_id' => self::STRANGER, 'created_at' => $now],
            ],
            'chat_messages' => [
                ['id' => 1, 'channel_id' => 1, 'user_id' => self::FOLLOWER, 'number' => 1, 'thread_id' => 7, 'type' => 'text', 'content' => '<t><p>raiz</p></t>', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_threads' => [
                ['id' => 7, 'channel_id' => 1, 'original_message_id' => 1, 'creator_id' => self::FOLLOWER, 'status' => 'open', 'replies_count' => 0, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_thread_user' => [
                ['thread_id' => 7, 'user_id' => self::FOLLOWER, 'notification_level' => 2, 'unread_count' => 5, 'last_read_message_id' => 1],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function attributesFor(int $actor): array
    {
        $response = $this->send($this->request('GET', '/api/chat-threads/7', ['authenticatedAs' => $actor]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data']['attributes'];
    }

    public function test_a_second_reader_does_not_get_the_first_readers_membership(): void
    {
        $this->assertTrue($this->attributesFor(self::FOLLOWER)['isParticipating']);

        $stranger = $this->attributesFor(self::STRANGER);

        $this->assertFalse($stranger['isParticipating']);
        $this->assertSame(0, $stranger['unreadCount']);
    }
}
