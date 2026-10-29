<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;

/**
 * `createChannel` cria os dois tipos de canal.
 *
 * Público ou privado é uma escolha na criação, não um segundo direito: um grupo
 * confiável para abrir uma sala decide quem pode achá-la. Este teste impede que
 * uma permissão `createPrivateChannel` separada volte.
 *
 * Os dois casos são conferidos na resposta *e* na linha gravada: a resposta é o
 * que o modal lê; a linha é contra o que a visibilidade filtra. Um valor que
 * chega a uma e não à outra é uma escrita pela metade.
 */
class CreatePrivateChannelTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt de "too-obscure", o mesmo hash que o `normalUser()` traz. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    /** Tem `createChannel` e nada além de usar o chat. */
    private const CREATOR = 3;

    /** Mesmos grupos que CREATOR, mas a conta nunca foi confirmada. */
    private const UNCONFIRMED = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-chat');

        // A permissão só conta com os canais nas mãos dos membros; no modo padrão
        // "administrators" ninguém mais cria, diga a grade o que disser. Registrado
        // antes do boot, como o harness pede para settings.
        $this->setting('ramon-chat.channel_ownership', 'members');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                [
                    'id'                 => self::CREATOR,
                    'username'           => 'creator',
                    'password'           => self::PASSWORD_HASH,
                    'email'              => 'creator@machine.local',
                    'is_email_confirmed' => 1,
                ],
                [
                    'id'                 => self::UNCONFIRMED,
                    'username'           => 'unconfirmed',
                    'password'           => self::PASSWORD_HASH,
                    'email'              => 'unconfirmed@machine.local',
                    'is_email_confirmed' => 0,
                ],
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Builder', 'name_plural' => 'Builders'],
            ],
            'group_user' => [
                ['user_id' => self::CREATOR, 'group_id' => 100],
                ['user_id' => self::UNCONFIRMED, 'group_id' => 100],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => 100, 'permission' => 'ramon-chat.createChannel'],
            ],
        ]);
    }

    private function create(int $as, bool $private): \Psr\Http\Message\ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => [
                    // `type` é o que o ChannelFormModal manda na criação, e o schema exige; sem
                    // ele a requisição nem chega à pergunta destes testes.
                    'type'      => 'category',
                    'name'      => $private ? 'secret room' : 'open room',
                    'isPrivate' => $private,
                ]]],
            ])
        );
    }

    private function assertCreated(int $as, bool $private): void
    {
        $response = $this->create($as, $private);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame($private, (bool) ($body['data']['attributes']['isPrivate'] ?? null), 'the response');

        $this->assertSame(
            $private ? 1 : 0,
            (int) $this->database()->table('chat_channels')
                ->where('id', $body['data']['id'])
                ->value('is_private'),
            'the stored row'
        );
    }

    public function test_create_channel_creates_a_public_one(): void
    {
        $this->assertCreated(self::CREATOR, false);
    }

    public function test_the_same_permission_creates_a_private_one(): void
    {
        $this->assertCreated(self::CREATOR, true);
    }

    public function test_an_admin_creates_both(): void
    {
        $this->assertCreated(1, false);
        $this->assertCreated(1, true);
    }

    public function test_a_member_without_the_permission_creates_neither(): void
    {
        // A migration de posse dá `createChannel` ao grupo Member, então "membro sem
        // a permissão" sai de uma revogação, que é também o que um operador faz para
        // restringir a criação a um grupo menor.
        $this->database()->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'ramon-chat.createChannel')
            ->delete();

        $this->assertSame(403, $this->create(2, false)->getStatusCode());
        $this->assertSame(403, $this->create(2, true)->getStatusCode());
    }

    /**
     * A grade pode dizer sim enquanto a resposta é não.
     *
     * `createChannel` exige `ramon-chat.use` *e* `ramon-chat.createChannel`, e o
     * core resolve conta não confirmada só como GUEST: User::permissions() só junta
     * MEMBER e os grupos do usuário com `is_email_confirmed`. O mesmo vale para
     * conta *suspensa*, que o flarum/suspend rebaixa a GUEST pelo mesmo processor.
     */
    public function test_an_unconfirmed_account_is_refused_despite_the_group(): void
    {
        $this->assertSame(
            403,
            $this->create(self::UNCONFIRMED, false)->getStatusCode(),
            'unconfirmed, in a group that holds createChannel'
        );

        // O controle: mesmos grupos, mesmas permissões, conta confirmada.
        $this->assertSame(201, $this->create(self::CREATOR, false)->getStatusCode());
    }

    /**
     * O corpo exato que o ChannelFormModal manda, repetido por um criador não admin.
     *
     * O json-api-server responde 403 a um campo apenas *presente* e não gravável,
     * então um único atributo só de admin no payload recusa a requisição inteira.
     * Fixtures enxutas, com três atributos em vez dos treze do modal, não pegam isso.
     */
    public function test_the_modal_payload_is_accepted_from_a_non_admin(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => self::CREATOR,
                'json'            => ['data' => ['type' => 'chat-channels', 'attributes' => [
                    'name'                     => 'canal teste privado',
                    'description'              => null,
                    'emoji'                    => null,
                    'threadingEnabled'         => true,
                    'slowModeSeconds'          => 0,
                    'maxMessageLength'         => null,
                    'allowChannelWideMentions' => true,
                    'autoJoin'                 => false,
                    'autoJoinOnReply'          => false,
                    'isPrivate'                => true,
                    'postPermission'           => 'all',
                    'postDiscussions'          => false,
                    'tagId'                    => null,
                    'type'                     => 'category',
                ]]],
            ])
        );

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_a_creator_cannot_switch_auto_join_on(): void
    {
        // O campo continua aceito (o formulário o manda em todo save, com `false`),
        // mas ligá-lo põe toda conta do fórum na sala, o que não cabe ao criador.
        // Recusado em vez de descartado, para o criador ser avisado em vez de achar a
        // chave desligada sem aviso.
        $response = $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => self::CREATOR,
                'json'            => ['data' => ['attributes' => [
                    'type'     => 'category',
                    'name'     => 'default room for members',
                    'autoJoin' => true,
                ]]],
            ])
        );

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, $this->database()->table('chat_channels')->where('name', 'default room for members')->count());
    }

    public function test_an_admin_can_switch_auto_join_on(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels', [
                'authenticatedAs' => 1,
                'json'            => ['data' => ['attributes' => [
                    'type'     => 'category',
                    'name'     => 'default room',
                    'autoJoin' => true,
                ]]],
            ])
        );

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $id = json_decode((string) $response->getBody(), true)['data']['id'];

        $this->assertSame(
            1,
            (int) $this->database()->table('chat_channels')->where('id', $id)->value('auto_join')
        );
    }
}
