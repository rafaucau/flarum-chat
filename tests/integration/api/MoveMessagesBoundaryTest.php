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
use Psr\Http\Message\ResponseInterface;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Até onde mover mensagens pode levá-las.
 *
 * Como no Discourse, mover não amplia o público: nada sai de uma conversa
 * direta, e o que é de um canal privado não vai para um aberto (os anexos
 * seguem a mensagem e passariam a ser servidos a quem lê o destino). E uma
 * mudança parte de um canal só, para uma seleção esquecida em outro canal não
 * ir junto.
 */
class MoveMessagesBoundaryTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const MOD = 3;
    private const PARTNER = 4;

    private const CH_PUBLIC = 1;
    private const CH_OTHER_PUBLIC = 2;
    private const CH_PRIVATE = 3;
    private const CH_PRIVATE_TOO = 4;
    private const DM = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();
        $channel = fn (int $id, string $type, int $private) => ['id' => $id, 'type' => $type, 'name' => 'c'.$id, 'slug' => 'c'.$id, 'status' => 'open', 'is_private' => $private, 'created_at' => $now, 'updated_at' => $now];
        $member = fn (int $channel, int $user) => ['channel_id' => $channel, 'user_id' => $user, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        $message = fn (int $id, int $channel, int $number) => ['id' => $id, 'channel_id' => $channel, 'user_id' => self::PARTNER, 'number' => $number, 'type' => 'text', 'content' => '<t>m'.$id.'</t>', 'created_at' => $now, 'updated_at' => $now];

        $this->prepareDatabase([
            'users' => [
                ['id' => self::MOD, 'username' => 'mod', 'email' => 'mod@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1],
                ['id' => self::PARTNER, 'username' => 'partner', 'email' => 'partner@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => self::MOD, 'group_id' => Group::MODERATOR_ID],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MODERATOR_ID, 'permission' => 'ramon-chat.moderate'],
            ],
            'chat_channels' => [
                $channel(self::CH_PUBLIC, 'category', 0),
                $channel(self::CH_OTHER_PUBLIC, 'category', 0),
                $channel(self::CH_PRIVATE, 'category', 1),
                $channel(self::CH_PRIVATE_TOO, 'category', 1),
                $channel(self::DM, 'direct', 0),
            ],
            'chat_channel_user' => [
                $member(self::CH_PUBLIC, self::MOD),
                $member(self::CH_OTHER_PUBLIC, self::MOD),
                $member(self::CH_PRIVATE, self::MOD),
                $member(self::CH_PRIVATE_TOO, self::MOD),
                $member(self::DM, self::MOD),
                $member(self::DM, self::PARTNER),
            ],
            'chat_messages' => [
                $message(1, self::CH_PRIVATE, 1),
                $message(2, self::DM, 1),
                $message(3, self::CH_OTHER_PUBLIC, 1),
                $message(4, self::CH_PRIVATE, 2),
            ],
        ]);
    }

    /** @param int[] $ids */
    private function move(array $ids, int $target): ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/chat/messages/move', [
                'authenticatedAs' => self::MOD,
                'json'            => ['data' => ['attributes' => ['messageIds' => $ids, 'channelId' => $target]]],
            ])
        );
    }

    private function channelOf(int $messageId): int
    {
        return (int) $this->database()->table('chat_messages')->where('id', $messageId)->value('channel_id');
    }

    public function test_nothing_leaves_a_direct_conversation(): void
    {
        $this->assertSame(422, $this->move([2], self::CH_PUBLIC)->getStatusCode());
        $this->assertSame(self::DM, $this->channelOf(2));
    }

    public function test_a_private_channel_does_not_move_into_an_open_one(): void
    {
        $this->assertSame(422, $this->move([1], self::CH_PUBLIC)->getStatusCode());
        $this->assertSame(self::CH_PRIVATE, $this->channelOf(1));
    }

    public function test_one_move_takes_messages_from_one_channel(): void
    {
        $this->assertSame(422, $this->move([1, 3], self::CH_PRIVATE_TOO)->getStatusCode());
        $this->assertSame(self::CH_PRIVATE, $this->channelOf(1));
        $this->assertSame(self::CH_OTHER_PUBLIC, $this->channelOf(3));
    }

    public function test_moves_that_do_not_widen_the_audience_still_work(): void
    {
        $this->assertSame(200, $this->move([1, 4], self::CH_PRIVATE_TOO)->getStatusCode());
        $this->assertSame(self::CH_PRIVATE_TOO, $this->channelOf(1));

        $this->assertSame(200, $this->move([3], self::CH_PUBLIC)->getStatusCode());
        $this->assertSame(self::CH_PUBLIC, $this->channelOf(3));
    }
}
