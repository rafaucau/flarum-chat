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
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Um fórum que libera o chat a um grupo só.
 *
 * Com `ramon-chat.use` dado só a um grupo, o botão do cabeçalho, a rota `/chat`
 * e o badge de canais não podem aparecer para quem está fora dele. São três
 * causas distintas, cada uma conferida abaixo.
 *
 * A fixture *não* dá `ramon-chat.use` a MEMBER: a extensão faz isso na
 * instalação e o fórum pode revogar, que é o estado reproduzido aqui.
 */
class RestrictedChatAccessTest extends TestCase
{
    use ResetsVisibilityScopers;

    use RetrievesAuthorizedUsers;

    /** BCrypt de "too-obscure", o mesmo hash que o `normalUser()` traz. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    /**
     * O fof/upload carrega os arquivos do ator num hook `after` do endpoint Show
     * do fórum, e cada app que a suíte sobe com fof-upload registra o hook de novo.
     * Depois desses testes, uma requisição aqui repete a carga uma vez por boot
     * anterior. É artefato de compartilhar o processo, não do chat.
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['fof_upload_files'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'staffer', 'password' => self::PASSWORD_HASH, 'email' => 'staffer@machine.local', 'is_email_confirmed' => 1],
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Staff', 'name_plural' => 'Staff'],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 100],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => 100, 'permission' => 'ramon-chat.use'],
            ],
        ]);

        $this->closeChatToMembers();
    }

    /**
     * Revoga as permissões de chat que a extensão dá na instalação.
     *
     * Não é opcional: ligar a extensão roda `2026_07_29_000013`, que dá
     * `ramon-chat.use` a MEMBER, e toda conta confirmada é MEMBER implicitamente.
     * O `populateDatabase()` do harness usa `updateOrInsert` em vez de substituir a
     * tabela, então listar o grupo 100 acima não tira essa permissão; sem isto,
     * nenhum dos gates abaixo é exercitado.
     *
     * É também o que um fórum restrito a um grupo faz: o admin revoga o padrão e
     * adiciona o grupo dele.
     */
    private function closeChatToMembers(): void
    {
        $this->database()->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'like', 'ramon-chat.%')
            ->delete();
    }

    private function canUseChat(int $as): bool
    {
        $response = $this->send($this->request('GET', '/api', ['authenticatedAs' => $as]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);

        return (bool) ($body['data']['attributes']['canUseChat'] ?? false);
    }

    public function test_the_header_button_is_not_offered_to_a_member_without_the_permission(): void
    {
        // A base do botão. Passa pela policy, não pelo fallback do Gate, que também
        // responderia ao catch-all global `can()` de outras extensões.
        $this->assertFalse($this->canUseChat(2));
    }

    public function test_the_header_button_is_offered_to_the_permitted_group(): void
    {
        $this->assertTrue($this->canUseChat(3));
    }

    public function test_the_chat_route_is_not_found_for_a_member_without_the_permission(): void
    {
        $response = $this->send($this->request('GET', '/chat', ['authenticatedAs' => 2]));

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function test_the_chat_route_serves_the_permitted_group(): void
    {
        $response = $this->send($this->request('GET', '/chat', ['authenticatedAs' => 3]));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_an_auto_join_channel_only_includes_users_who_may_use_the_chat(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => 1,
                'json'            => ['data' => ['attributes' => [
                    'name'     => 'Announcements',
                    'type'     => 'category',
                    'autoJoin' => true,
                ]]],
            ])
        );

        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        $channelId = (int) json_decode((string) $response->getBody(), true)['data']['id'];

        $joined = $this->database()->table('chat_channel_user')
            ->where('channel_id', $channelId)
            ->pluck('user_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        // Ninguém além do criador ganha linha: quem está num canal para todos sai de
        // quem pode vê-lo.
        $this->assertSame([1], $joined);

        // 3 está no grupo permitido. 2 é membro comum e não pode ganhar linha num
        // canal que não abre, senão ele aparece na barra lateral e no badge de não
        // lidas.
        $this->assertContains($channelId, $this->followedBy(3));
        $this->assertNotContains($channelId, $this->followedBy(2));
    }

    /**
     * @return int[]
     */
    private function followedBy(int $as): array
    {
        $response = $this->send(
            $this->request('GET', '/api/chat-channels', ['authenticatedAs' => $as])
                ->withQueryParams(['filter' => ['following' => '1']])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        return array_map(
            static fn (array $row) => (int) $row['id'],
            json_decode((string) $response->getBody(), true)['data']
        );
    }

    public function test_a_stale_membership_does_not_feed_the_badge(): void
    {
        // Linhas que sobreviveram à permissão que as gravou: não são alcançáveis,
        // então também não podem ser contadas.
        $this->database()->table('chat_channels')->insert([
            'id'         => 50,
            'type'       => 'category',
            'name'       => 'stale',
            'slug'       => 'stale',
            'status'     => 'open',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->database()->table('chat_channel_user')->insert([
            'channel_id'   => 50,
            'user_id'      => 2,
            'following'    => 1,
            'muted'        => 0,
            'unread_count' => 5,
            'created_at'   => Carbon::now()->toDateTimeString(),
            'updated_at'   => Carbon::now()->toDateTimeString(),
        ]);

        $response = $this->send($this->request('GET', '/api/users/2', ['authenticatedAs' => 2]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];

        $this->assertSame(0, $attributes['chatUnreadMessagesCount']);
        $this->assertSame(0, $attributes['chatUnreadChannelsCount']);
    }

    public function test_a_membership_in_an_unreachable_channel_does_not_feed_the_badge(): void
    {
        // O caso que um guard geral "este ator pode usar o chat" não pega: o usuário
        // 3 tem a permissão, então o gate diz sim, mas o canal em si sumiu. Contar
        // por Channel::whereVisibleTo() pega isso, no mesmo formato que o
        // flarum/messages usa para `messageCount`.
        $this->database()->table('chat_channels')->insert([
            'id'         => 60,
            'type'       => 'category',
            'name'       => 'deleted',
            'slug'       => 'deleted',
            'status'     => 'open',
            'deleted_at' => Carbon::now()->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->database()->table('chat_channel_user')->insert([
            'channel_id'            => 60,
            'user_id'               => 3,
            'following'             => 1,
            'muted'                 => 0,
            'unread_count'          => 7,
            'unread_mentions_count' => 2,
            'created_at'            => Carbon::now()->toDateTimeString(),
            'updated_at'            => Carbon::now()->toDateTimeString(),
        ]);

        $response = $this->send($this->request('GET', '/api/users/3', ['authenticatedAs' => 3]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $attributes = json_decode((string) $response->getBody(), true)['data']['attributes'];

        $this->assertSame(0, $attributes['chatUnreadMessagesCount']);
        $this->assertSame(0, $attributes['chatUnreadChannelsCount']);
        $this->assertSame(0, $attributes['chatUnreadMentionsCount']);
    }
}
