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
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Canais de categoria ligados a uma tag, com o flarum/tags ligado de verdade.
 *
 * Confere os dois sentidos: as permissões herdadas da tag restringem o canal, e
 * um arquivamento cai na categoria ligada.
 *
 * Roda em processo próprio porque `ScopeVisibilityTrait::$visibilityScopers` é
 * um registro estático que cresce a cada boot e nunca é limpo. Uma classe irmã
 * que liga só o ramon-chat deixa um scoper montado sem tags, sem o ramo de tag,
 * que tiraria todo canal ligado a tag dos resultados. O vazamento é do harness:
 * uma requisição real sobe um app só.
 */
#[RunTestsInSeparateProcesses]
class TagBoundChannelTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt de "too-obscure", o mesmo hash que o `normalUser()` traz. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    protected function setUp(): void
    {
        parent::setUp();

        // A ordem importa: tags precisa estar ligado antes de o chat ler dele.
        $this->extension('flarum-tags', 'ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'insider', 'password' => self::PASSWORD_HASH, 'email' => 'insider@machine.local', 'is_email_confirmed' => 1],
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Insider', 'name_plural' => 'Insiders'],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 100],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                // A tag restrita abre só para o grupo 100, que é o que o canal ligado deve
                // herdar.
                ['group_id' => 100, 'permission' => 'tag2.viewForum'],
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Lounge', 'slug' => 'lounge', 'position' => 0, 'is_restricted' => 0],
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => 1],
            ],
            'chat_channels' => [
                $this->channel(1, 'lounge-chat', 1),
                $this->channel(2, 'staff-chat', 2),
                $this->channel(3, 'open-chat', null),
            ],
        ]);
    }

    private function channel(int $id, string $slug, ?int $tagId): array
    {
        return [
            'id'         => $id,
            'type'       => 'category',
            'name'       => $slug,
            'slug'       => $slug,
            'status'     => 'open',
            'tag_id'     => $tagId,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return int[] */
    private function visibleChannelIds(int $as): array
    {
        $response = $this->send($this->request('GET', '/api/chat-channels', ['authenticatedAs' => $as]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);
        $ids = array_map(static fn (array $row) => (int) $row['id'], $body['data'] ?? []);

        sort($ids);

        return $ids;
    }

    public function test_listing_channels_does_not_error_when_tags_is_enabled(): void
    {
        // O ramo de tag monta uma subquery em `tags`, então só pode ser alcançado
        // quando a extensão está ligada de fato, não só presente no vendor/.
        $this->assertNotEmpty($this->visibleChannelIds(1));
    }

    public function test_a_channel_on_a_restricted_tag_is_hidden(): void
    {
        $this->assertSame([1, 3], $this->visibleChannelIds(2), 'staff-chat inherits the restricted tag');
    }

    public function test_a_channel_on_a_restricted_tag_is_visible_to_the_permitted_group(): void
    {
        $this->assertSame([1, 2, 3], $this->visibleChannelIds(3));
    }

    public function test_an_admin_sees_every_channel(): void
    {
        $this->assertSame([1, 2, 3], $this->visibleChannelIds(1));
    }

    public function test_archiving_files_the_discussion_under_the_bound_tag(): void
    {
        // ChannelPolicy::archive recusa canal aberto: arquivar é o passo depois de
        // fechar, não uma alternativa.
        $this->database()->table('chat_channels')->where('id', 1)->update(['status' => 'closed']);

        $response = $this->send(
            $this->request('POST', '/api/chat-channels/1/archive', [
                'authenticatedAs' => 1,
                'json'            => ['data' => ['attributes' => ['title' => 'Lounge transcript']]],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $discussionId = $this->database()->table('chat_channels')->where('id', 1)->value('archived_discussion_id');

        $this->assertNotNull($discussionId, 'the channel records the discussion it archived into');

        // O flarum/tags registra a relação por resolveRelationUsing(), então
        // `method_exists($discussion, 'tags')` é falso mesmo com tags ligado.
        $this->assertSame(
            [1],
            $this->database()->table('discussion_tag')
                ->where('discussion_id', $discussionId)
                ->pluck('tag_id')
                ->map(static fn ($id) => (int) $id)
                ->all()
        );
    }
}
