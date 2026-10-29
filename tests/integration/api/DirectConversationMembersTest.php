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
 * Quem pode mudar os participantes de uma conversa direta.
 *
 * Como no Discourse: uma conversa a dois que já tem mensagens é dos dois, então
 * quem a abriu não chama uma terceira pessoa (que leria todo o histórico) nem
 * tira a outra. Grupos e conversas ainda vazias continuam abertos ao criador.
 */
class DirectConversationMembersTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const CREATOR = 3;
    private const PARTNER = 4;
    private const THIRD = 5;
    private const OUTSIDER = 6;

    private const DM_WITH_HISTORY = 1;
    private const DM_EMPTY = 2;
    private const GROUP_DM = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();
        $user = fn (int $id, string $name) => ['id' => $id, 'username' => $name, 'email' => $name.'@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1];
        $member = fn (int $channel, int $id) => ['channel_id' => $channel, 'user_id' => $id, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now];

        $this->prepareDatabase([
            'users' => [
                $user(self::CREATOR, 'creator'),
                $user(self::PARTNER, 'partner'),
                $user(self::THIRD, 'third'),
                $user(self::OUTSIDER, 'outsider'),
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.startDirect'],
            ],
            'chat_channels' => [
                ['id' => self::DM_WITH_HISTORY, 'type' => 'direct', 'creator_id' => self::CREATOR, 'status' => 'open', 'messages_count' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['id' => self::DM_EMPTY, 'type' => 'direct', 'creator_id' => self::CREATOR, 'status' => 'open', 'messages_count' => 0, 'created_at' => $now, 'updated_at' => $now],
                ['id' => self::GROUP_DM, 'type' => 'direct', 'creator_id' => self::CREATOR, 'status' => 'open', 'messages_count' => 1, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                $member(self::DM_WITH_HISTORY, self::CREATOR),
                $member(self::DM_WITH_HISTORY, self::PARTNER),
                $member(self::DM_EMPTY, self::CREATOR),
                $member(self::DM_EMPTY, self::PARTNER),
                $member(self::GROUP_DM, self::CREATOR),
                $member(self::GROUP_DM, self::PARTNER),
                $member(self::GROUP_DM, self::THIRD),
            ],
            'chat_messages' => [
                ['id' => 1, 'channel_id' => self::DM_WITH_HISTORY, 'user_id' => self::PARTNER, 'number' => 1, 'type' => 'text', 'content' => '<t>só entre nós</t>', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'channel_id' => self::GROUP_DM, 'user_id' => self::PARTNER, 'number' => 1, 'type' => 'text', 'content' => '<t>oi grupo</t>', 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    private function invite(int $channelId, int $userId): ResponseInterface
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        return $this->send(
            $this->request('POST', '/api/chat-channels/'.$channelId.'/members', [
                'authenticatedAs' => self::CREATOR,
                'json'            => ['data' => ['attributes' => ['userIds' => [$userId]]]],
            ])
        );
    }

    private function remove(int $channelId, int $userId): ResponseInterface
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        return $this->send(
            $this->request('POST', '/api/chat-channels/'.$channelId.'/members/remove', [
                'authenticatedAs' => self::CREATOR,
                'json'            => ['data' => ['attributes' => ['userId' => $userId]]],
            ])
        );
    }

    public function test_a_one_to_one_conversation_with_history_takes_no_one_else(): void
    {
        $this->assertSame(403, $this->invite(self::DM_WITH_HISTORY, self::THIRD)->getStatusCode());
        $this->assertSame(0, $this->database()->table('chat_channel_invites')->where('channel_id', self::DM_WITH_HISTORY)->count());
    }

    public function test_the_creator_cannot_remove_the_other_person_from_a_one_to_one(): void
    {
        $this->assertSame(403, $this->remove(self::DM_WITH_HISTORY, self::PARTNER)->getStatusCode());
        $this->assertNull($this->database()->table('chat_channel_user')->where('channel_id', self::DM_WITH_HISTORY)->where('user_id', self::PARTNER)->value('left_at'));
    }

    public function test_an_empty_conversation_can_still_grow(): void
    {
        $this->assertSame(200, $this->invite(self::DM_EMPTY, self::THIRD)->getStatusCode());
    }

    public function test_a_group_conversation_can_still_grow(): void
    {
        $this->assertSame(200, $this->invite(self::GROUP_DM, self::OUTSIDER)->getStatusCode());
    }

    public function test_invites_respect_the_participant_cap(): void
    {
        $now = Carbon::now()->toDateTimeString();
        $extra = [];

        for ($id = 100; $id < 117; $id++) {
            $extra[] = ['id' => $id, 'username' => 'u'.$id, 'email' => 'u'.$id.'@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1];
        }

        // 3 no grupo + 17 = 20, o teto; mais um passaria dele.
        $this->prepareDatabase(['users' => array_merge($extra, [
            ['id' => 200, 'username' => 'u200', 'email' => 'u200@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1],
        ])]);

        $this->app()->getContainer()->make(Repository::class)->clear();

        $response = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::GROUP_DM.'/members', [
                'authenticatedAs' => self::CREATOR,
                'json'            => ['data' => ['attributes' => ['userIds' => array_merge(range(100, 116), [200])]]],
            ])
        );

        $this->assertSame(422, $response->getStatusCode());
    }
}
