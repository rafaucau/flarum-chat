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
use Ramon\Chat\Thread;

/**
 * `ramon-chat.createThread` controla só *iniciar* um tópico.
 *
 * Sem ela a pessoa ainda conversa: mensagem comum no canal e resposta num tópico
 * que já existe. Errar para o lado restritivo calaria gente em vez de só
 * limitar, por isso as duas metades são conferidas, não só a recusa.
 */
class CreateThreadPermissionTest extends TestCase
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
                ['id' => 3, 'username' => 'brancher', 'password' => self::PASSWORD_HASH, 'email' => 'brancher@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                // createThread de propósito NÃO vai para membros; o usuário 3 a recebe por um
                // grupo próprio, para os dois casos diferirem só por essa permissão.
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Brancher', 'name_plural' => 'Branchers'],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 100],
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
                    'content'    => '<t>branch off me</t>',
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ],
            ],
        ]);

        // `2026_07_30_000003` dá createThread a MEMBER, e as migrations da extensão
        // rodam depois de prepareDatabase(): sem esta revogação o usuário 2 teria a
        // permissão e o caso "recusado" não testaria nada.
        $this->database()->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.createThread')
            ->delete();

        $this->database()->table('group_permission')->insert([
            'group_id'   => 100,
            'permission' => 'ramon-chat.createThread',
        ]);
    }

    protected function send_message(int $as, array $attributes)
    {
        return $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => array_merge(['channelId' => 1], $attributes)]],
            ])
        );
    }

    public function test_without_the_permission_a_plain_message_still_sends(): void
    {
        $response = $this->send_message(2, ['content' => 'just talking']);

        $this->assertEquals(201, $response->getStatusCode());
    }

    public function test_without_the_permission_starting_a_thread_is_refused(): void
    {
        $response = $this->send_message(2, [
            'content'      => 'branching',
            'replyToId'    => 1,
            'createThread' => true,
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertSame(0, Thread::query()->count(), 'no thread was created');
    }

    public function test_with_the_permission_a_thread_is_created(): void
    {
        $response = $this->send_message(3, [
            'content'      => 'branching',
            'replyToId'    => 1,
            'createThread' => true,
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertSame(1, Thread::query()->count());
    }

    public function test_replying_inside_an_existing_thread_needs_no_permission(): void
    {
        // Criado por quem tem a permissão.
        $this->send_message(3, ['content' => 'root', 'replyToId' => 1, 'createThread' => true]);

        $thread = Thread::query()->firstOrFail();

        $response = $this->send_message(2, [
            'content'  => 'me too',
            'threadId' => $thread->id,
        ]);

        $this->assertEquals(201, $response->getStatusCode(), 'replying is not gated by createThread');

        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals($thread->id, $body['data']['attributes']['threadId']);
    }

    /** Tópicos têm um nível só: uma resposta dentro de um não abre outro. */
    public function test_a_thread_reply_cannot_be_branched(): void
    {
        $this->send_message(3, ['content' => 'root', 'replyToId' => 1, 'createThread' => true]);

        $thread = Thread::query()->firstOrFail();
        $reply = Message::query()->where('thread_id', $thread->id)->latest('id')->firstOrFail();

        $response = $this->send_message(3, [
            'content'      => 'nesting',
            'replyToId'    => $reply->id,
            'createThread' => true,
        ]);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertSame(1, Thread::query()->count());
    }
}
