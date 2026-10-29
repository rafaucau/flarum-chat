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
 * A lista de membros sai da leitura de um canal, não da listagem: lá,
 * `?include=participants` traria todos os membros de até 50 canais numa
 * requisição só, sem paginação.
 */
class ChannelListIncludesTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const READER = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();
        $users = [['id' => self::READER, 'username' => 'reader', 'email' => 'reader@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1]];
        $members = [['channel_id' => 1, 'user_id' => self::READER, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now]];

        for ($id = 100; $id < 130; $id++) {
            $users[] = ['id' => $id, 'username' => 'u'.$id, 'email' => 'u'.$id.'@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1];
            $members[] = ['channel_id' => 1, 'user_id' => $id, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }

        $this->prepareDatabase([
            'users' => $users,
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'geral', 'slug' => 'geral', 'status' => 'open', 'user_count' => 31, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => $members,
        ]);
    }

    /** @return array<string, mixed> */
    private function get(string $path, array $query): array
    {
        $response = $this->send($this->request('GET', $path, ['authenticatedAs' => self::READER])->withQueryParams($query));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function includedUsers(array $document): int
    {
        return count(array_filter($document['included'] ?? [], fn (array $row) => $row['type'] === 'users'));
    }

    public function test_the_list_does_not_carry_every_member(): void
    {
        $document = $this->get('/api/chat-channels', ['include' => 'participants']);

        $this->assertLessThan(5, $this->includedUsers($document));
    }

    public function test_reading_one_channel_still_lists_its_members(): void
    {
        $document = $this->get('/api/chat-channels/1', ['include' => 'participants']);

        $this->assertGreaterThanOrEqual(30, $this->includedUsers($document));
    }
}
