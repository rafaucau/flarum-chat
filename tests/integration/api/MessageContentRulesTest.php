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
use Psr\Http\Message\ResponseInterface;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Regras de conteúdo do envio: o limite de um canal só encurta o do fórum, e
 * `uploadIds` que não são anexos de verdade não tornam válida uma mensagem
 * vazia.
 */
class MessageContentRulesTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const AUTHOR = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');
        $this->setting('ramon-chat.max_message_length', '200');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                ['id' => self::AUTHOR, 'username' => 'author', 'email' => 'author@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'geral', 'slug' => 'geral', 'status' => 'open', 'max_message_length' => 50000, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'type' => 'category', 'name' => 'curto', 'slug' => 'curto', 'status' => 'open', 'max_message_length' => 100, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => self::AUTHOR, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
                ['channel_id' => 2, 'user_id' => self::AUTHOR, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    private function say(int $channelId, string $content, array $uploadIds = []): ResponseInterface
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        return $this->send($this->request('POST', '/api/chat-messages', [
            'authenticatedAs' => self::AUTHOR,
            'json'            => ['data' => ['type' => 'chat-messages', 'attributes' => ['channelId' => $channelId, 'content' => $content, 'uploadIds' => $uploadIds]]],
        ]));
    }

    public function test_a_channel_cannot_raise_the_forum_limit(): void
    {
        $this->assertSame(422, $this->say(1, str_repeat('a', 201))->getStatusCode());
        $this->assertSame(201, $this->say(1, str_repeat('a', 200))->getStatusCode());
    }

    public function test_a_channel_can_still_lower_it(): void
    {
        $this->assertSame(422, $this->say(2, str_repeat('a', 101))->getStatusCode());
    }

    public function test_made_up_attachments_do_not_make_an_empty_message_valid(): void
    {
        $this->assertSame(422, $this->say(1, '', ['x'])->getStatusCode());
        $this->assertSame(422, $this->say(1, '   ', [999999])->getStatusCode());
        $this->assertSame(0, $this->database()->table('chat_messages')->count());
    }
}
