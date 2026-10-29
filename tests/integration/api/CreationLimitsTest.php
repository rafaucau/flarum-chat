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
use Laminas\Diactoros\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Tetos do que um membro cria: conversas diretas, anexos e canais.
 *
 * Nenhum dos três tinha limite. Variar os participantes abria DMs sem fim na
 * barra lateral de qualquer pessoa, um loop de uploads enchia o disco, e quem
 * pode criar canais criava quantos quisesse.
 */
class CreationLimitsTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const MEMBER = 3;

    /** Um PNG 1×1 transparente. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');
        $this->setting('ramon-chat.channel_ownership', 'members');

        $now = Carbon::now()->toDateTimeString();
        $users = [['id' => self::MEMBER, 'username' => 'member', 'email' => 'member@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1]];

        for ($id = 100; $id < 125; $id++) {
            $users[] = ['id' => $id, 'username' => 'u'.$id, 'email' => 'u'.$id.'@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1];
        }

        $this->prepareDatabase([
            'users' => $users,
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.startDirect'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.upload'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.createChannel'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'geral', 'slug' => 'geral', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => self::MEMBER, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    private function post(string $path, array $json): ResponseInterface
    {
        return $this->send($this->request('POST', $path, ['authenticatedAs' => self::MEMBER, 'json' => $json]));
    }

    private function upload(): ResponseInterface
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chat');
        file_put_contents($tmp, base64_decode(self::PNG));

        return $this->send(
            $this->request('POST', '/api/chat/uploads', ['authenticatedAs' => self::MEMBER])
                ->withUploadedFiles(['file' => new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'pic.png', 'image/png')])
                ->withParsedBody(['channelId' => '1'])
        );
    }

    public function test_new_direct_conversations_are_throttled(): void
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        for ($id = 100; $id < 120; $id++) {
            $this->assertSame(201, $this->post('/api/chat/direct', ['data' => ['attributes' => ['userIds' => [$id]]]])->getStatusCode());
        }

        $this->assertSame(422, $this->post('/api/chat/direct', ['data' => ['attributes' => ['userIds' => [120]]]])->getStatusCode());

        // Reabrir uma conversa que já existe não cria nada, então não conta.
        $this->assertSame(200, $this->post('/api/chat/direct', ['data' => ['attributes' => ['userIds' => [100]]]])->getStatusCode());
    }

    public function test_pending_attachments_are_capped(): void
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(201, $this->upload()->getStatusCode());
        }

        $this->assertSame(422, $this->upload()->getStatusCode());
    }

    public function test_a_message_carries_a_bounded_number_of_attachments(): void
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        $ids = [];

        for ($i = 0; $i < 11; $i++) {
            $ids[] = (int) json_decode((string) $this->upload()->getBody(), true)['data']['id'];
        }

        $response = $this->post('/api/chat-messages', ['data' => ['type' => 'chat-messages', 'attributes' => ['channelId' => 1, 'content' => 'fotos', 'uploadIds' => $ids]]]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(11, $this->database()->table('chat_uploads')->whereNull('message_id')->count());
    }

    public function test_channel_creation_is_throttled(): void
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        $create = fn (int $i) => $this->post('/api/chat-channels', ['data' => ['type' => 'chat-channels', 'attributes' => ['type' => 'category', 'name' => 'canal '.$i]]])->getStatusCode();

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(201, $create($i));
        }

        $this->assertSame(422, $create(10));
    }
}
