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
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Ramon\Chat\Message;

/**
 * Fixar mensagens pela API de verdade.
 *
 * É teste de integração por causa da permissão: uma policy certa isoladamente
 * vale pouco se o endpoint nunca a consulta.
 */
class PinMessageTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt de "too-obscure", o mesmo hash que o `normalUser()` traz. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                // `password` é NOT NULL em `users`; sem ele a fixture inteira aborta. O
                // `normalUser()` traz o próprio, por isso só as linhas escritas à mão
                // precisam dele.
                ['id' => 3, 'username' => 'member', 'password' => self::PASSWORD_HASH, 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.startDirect'],
            ],
            'chat_channels' => [
                [
                    'id'                => 1,
                    'type'              => 'category',
                    'name'              => 'general',
                    'slug'              => 'general',
                    'status'            => 'open',
                    'threading_enabled' => 1,
                    'created_at'        => Carbon::now()->toDateTimeString(),
                    'updated_at'        => Carbon::now()->toDateTimeString(),
                ],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => 2, 'following' => 1, 'created_at' => Carbon::now()->toDateTimeString()],
                ['channel_id' => 1, 'user_id' => 3, 'following' => 1, 'created_at' => Carbon::now()->toDateTimeString()],
            ],
            'chat_messages' => [
                [
                    'id'         => 1,
                    'channel_id' => 1,
                    'user_id'    => 2,
                    'number'     => 1,
                    'type'       => 'text',
                    // O conteúdo gravado é XML do s9e/TextFormatter, não texto cru (toda escrita
                    // passa por `setContentAttribute`). Uma string pura aqui vira 500 na hora de
                    // serializar.
                    'content'    => '<t>pin me</t>',
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ],
            ],
        ]);
    }

    public function test_admin_can_pin_and_unpin(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-messages/1/pin', ['authenticatedAs' => 1])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertTrue($body['data']['attributes']['isPinned']);
        $this->assertNotNull(Message::query()->find(1)->pinned_at);

        // A mesma rota alterna, então duas chamadas deixam a mensagem desafixada.
        $this->send($this->request('POST', '/api/chat-messages/1/pin', ['authenticatedAs' => 1]));

        $message = Message::query()->find(1);
        $this->assertNull($message->pinned_at);
        $this->assertNull($message->pinned_by_id, 'attribution is cleared with the timestamp');
    }

    public function test_a_member_without_the_permission_cannot_pin(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-messages/1/pin', ['authenticatedAs' => 3])
        );

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertNull(Message::query()->find(1)->pinned_at, 'a refused request changes nothing');
    }

    public function test_a_guest_cannot_pin(): void
    {
        $response = $this->send($this->request('POST', '/api/chat-messages/1/pin'));

        // 400, não 401: o CheckCsrfToken vem antes da autenticação, então um POST de
        // visitante sem token nem chega à policy. O que importa é a recusa sem
        // mudança; o status é escolha do Flarum.
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertNull(Message::query()->find(1)->pinned_at, 'a refused request changes nothing');
    }

    public function test_pinned_messages_are_listable(): void
    {
        $this->send($this->request('POST', '/api/chat-messages/1/pin', ['authenticatedAs' => 1]));

        $response = $this->send(
            $this->request('GET', '/api/chat-messages', ['authenticatedAs' => 1])
                ->withQueryParams([
                    'filter' => ['channel' => '1', 'pinned' => '1'],
                    'sort'   => '-pinnedAt',
                ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $ids = array_map(
            fn (array $row) => (int) $row['id'],
            json_decode($response->getBody()->getContents(), true)['data']
        );

        $this->assertSame([1], $ids);
    }

    /** Uma listagem sem o filtro não é afetada. */
    public function test_unpinned_messages_still_appear_in_the_channel(): void
    {
        $response = $this->send(
            $this->request('GET', '/api/chat-messages', ['authenticatedAs' => 3])
                ->withQueryParams(['filter' => ['channel' => '1']])
        );

        $ids = array_map(
            fn (array $row) => (int) $row['id'],
            json_decode($response->getBody()->getContents(), true)['data']
        );

        $this->assertContains(1, $ids);
    }
}
