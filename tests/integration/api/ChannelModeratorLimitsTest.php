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
 * Até onde vai quem modera um canal.
 *
 * Apagar mensagens não seguia a hierarquia que remover membros já segue: um
 * moderador do canal apagava a mensagem do dono, de admins e da moderação do
 * chat (com os anexos, sem poder desfazer). E promover ou rebaixar um membro
 * oculto denunciava quem inspeciona o canal.
 */
class ChannelModeratorLimitsTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const ADMIN = 1;
    private const OWNER = 3;
    private const CHANNEL_MOD = 4;
    private const MEMBER = 5;
    private const CHAT_MOD = 6;
    private const OTHER_MOD = 7;
    private const INSPECTOR = 8;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');
        $this->setting('ramon-chat.channel_ownership', 'members');

        $now = Carbon::now()->toDateTimeString();
        $user = fn (int $id, string $name) => ['id' => $id, 'username' => $name, 'email' => $name.'@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1];
        $member = fn (int $id, bool $moderator = false, bool $hidden = false) => ['channel_id' => 1, 'user_id' => $id, 'is_moderator' => $moderator, 'hidden' => $hidden, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        $message = fn (int $id, int $author) => ['id' => $id, 'channel_id' => 1, 'user_id' => $author, 'number' => $id, 'type' => 'text', 'content' => '<t>m'.$id.'</t>', 'created_at' => $now, 'updated_at' => $now];

        $this->prepareDatabase([
            'users' => [
                $user(self::OWNER, 'owner'),
                $user(self::CHANNEL_MOD, 'chanmod'),
                $user(self::MEMBER, 'member'),
                $user(self::CHAT_MOD, 'chatmod'),
                $user(self::OTHER_MOD, 'othermod'),
                $user(self::INSPECTOR, 'inspector'),
            ],
            'group_user' => [
                ['user_id' => self::CHAT_MOD, 'group_id' => Group::MODERATOR_ID],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.manageOwnChannels'],
                ['group_id' => Group::MODERATOR_ID, 'permission' => 'ramon-chat.moderate'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'sala', 'slug' => 'sala', 'status' => 'open', 'creator_id' => self::OWNER, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                $member(self::OWNER),
                $member(self::CHANNEL_MOD, moderator: true),
                $member(self::MEMBER),
                $member(self::CHAT_MOD),
                $member(self::OTHER_MOD, moderator: true),
                $member(self::INSPECTOR, hidden: true),
                $member(self::ADMIN),
            ],
            'chat_messages' => [
                $message(1, self::OWNER),
                $message(2, self::ADMIN),
                $message(3, self::CHAT_MOD),
                $message(4, self::OTHER_MOD),
                $message(5, self::MEMBER),
                $message(6, self::MEMBER),
            ],
        ]);
    }

    private function delete(int $as, int $messageId): int
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        return $this->send($this->request('POST', '/api/chat-messages/'.$messageId.'/delete', ['authenticatedAs' => $as]))->getStatusCode();
    }

    private function promote(int $userId): ResponseInterface
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        return $this->send($this->request('POST', '/api/chat-channels/1/moderators', [
            'authenticatedAs' => self::OWNER,
            'json'            => ['data' => ['attributes' => ['userId' => $userId]]],
        ]));
    }

    public function test_a_channel_moderator_only_deletes_messages_of_people_below_them(): void
    {
        $this->assertSame(403, $this->delete(self::CHANNEL_MOD, 1), 'do dono');
        $this->assertSame(403, $this->delete(self::CHANNEL_MOD, 2), 'de um admin');
        $this->assertSame(403, $this->delete(self::CHANNEL_MOD, 3), 'da moderação do chat');
        $this->assertSame(403, $this->delete(self::CHANNEL_MOD, 4), 'de outro moderador do canal');
        $this->assertSame(204, $this->delete(self::CHANNEL_MOD, 5), 'de um membro');
    }

    public function test_the_owner_does_not_delete_messages_of_admins_or_chat_moderators(): void
    {
        $this->assertSame(403, $this->delete(self::OWNER, 2));
        $this->assertSame(403, $this->delete(self::OWNER, 3));
        $this->assertSame(204, $this->delete(self::OWNER, 4));
        $this->assertSame(204, $this->delete(self::CHAT_MOD, 6), 'a moderação do chat continua apagando');
    }

    public function test_a_hidden_member_is_not_a_member_to_the_owner(): void
    {
        $hidden = $this->promote(self::INSPECTOR);
        $stranger = $this->promote(999);

        $this->assertSame(422, $hidden->getStatusCode());
        $this->assertSame(0, (int) $this->database()->table('chat_channel_user')->where('user_id', self::INSPECTOR)->value('is_moderator'));
        $this->assertNotSame(200, $stranger->getStatusCode());
    }
}
