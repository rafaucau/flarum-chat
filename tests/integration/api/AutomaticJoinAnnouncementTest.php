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
 * A entrada que é efeito colateral, não chegada.
 *
 * `auto_join_on_reply` põe quem responde numa categoria no canal ligado a ela.
 * Anunciar essas entradas enterraria a conversa num fórum movimentado (cada
 * primeira resposta viraria uma linha de sistema), então o teste confere as
 * duas metades: a membership é criada e nada é narrado.
 *
 * Classe própria porque a fixture tem outro formato: tag, discussão e resposta,
 * que a matriz de acesso não usa.
 *
 * Processo separado pelo motivo descrito em TagBoundChannelTest: o registro de
 * scopers de visibilidade é estático e sobrevive entre boots.
 */
#[RunTestsInSeparateProcesses]
class AutomaticJoinAnnouncementTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt de "too-obscure", o mesmo hash que o `normalUser()` traz. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const REPLIER = 2;
    private const AUTHOR = 3;
    private const CHANNEL = 1;
    private const DISCUSSION = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                [
                    'id'                 => self::AUTHOR,
                    'username'           => 'author',
                    'password'           => self::PASSWORD_HASH,
                    'email'              => 'author@machine.local',
                    'is_email_confirmed' => 1,
                ],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'discussion.reply'],
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Lounge', 'slug' => 'lounge', 'position' => 0, 'is_restricted' => 0],
            ],
            // A discussão vem de linhas da fixture, não da API: `POST /api/discussions`
            // passa pelo `validateTagCount()` do flarum/tags, que monta `size:$min` a
            // partir de `flarum-tags.min_*_tags`. Num banco novo esses settings são null,
            // a regra vira `size:` e o Brick\Math responde 500.
            //
            // Só a resposta abaixo precisa ser real: é ela que dispara `Posted`, que o
            // JoinChannelsOnReply escuta.
            'discussions' => [
                [
                    'id'            => self::DISCUSSION,
                    'title'         => 'A thread in the lounge',
                    'slug'          => 'a-thread-in-the-lounge',
                    'user_id'       => self::AUTHOR,
                    'first_post_id' => 1,
                    'comment_count' => 1,
                    'created_at'    => Carbon::now()->toDateTimeString(),
                    'is_private'    => 0,
                ],
            ],
            'discussion_tag' => [
                ['discussion_id' => self::DISCUSSION, 'tag_id' => 1],
            ],
            'posts' => [
                [
                    'id'            => 1,
                    'discussion_id' => self::DISCUSSION,
                    'number'        => 1,
                    'user_id'       => self::AUTHOR,
                    'type'          => 'comment',
                    'content'       => '<t><p>opening post</p></t>',
                    'created_at'    => Carbon::now()->toDateTimeString(),
                    'is_private'    => 0,
                ],
            ],
            'chat_channels' => [
                [
                    'id'                 => self::CHANNEL,
                    'type'               => 'category',
                    'name'               => 'lounge-chat',
                    'slug'               => 'lounge-chat',
                    'status'             => 'open',
                    'tag_id'             => 1,
                    'is_private'         => 0,
                    'auto_join_on_reply' => 1,
                    'created_at'         => Carbon::now()->toDateTimeString(),
                    'updated_at'         => Carbon::now()->toDateTimeString(),
                ],
            ],
        ]);
    }

    /** @return string[] */
    private function systemKeys(): array
    {
        return $this->database()->table('chat_messages')
            ->where('channel_id', self::CHANNEL)
            ->where('type', 'system')
            ->orderBy('id')
            ->pluck('system_key')
            ->all();
    }

    private function isMember(int $userId): bool
    {
        return $this->database()->table('chat_channel_user')
            ->where('channel_id', self::CHANNEL)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
    }

    public function test_replying_joins_the_bound_channel_without_announcing_it(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/posts', [
                'authenticatedAs' => self::REPLIER,
                'json'            => ['data' => [
                    'attributes'    => ['content' => 'replying here'],
                    'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) self::DISCUSSION]]],
                ]],
            ])
        );

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $this->assertTrue($this->isMember(self::REPLIER), 'the reply still puts them in the channel');
        $this->assertSame([], $this->systemKeys(), 'and nothing is narrated about it');
    }

    public function test_a_deliberate_join_in_the_same_channel_is_still_announced(): void
    {
        // O controle: mesmo canal, ator e tabela de memberships; a única diferença é
        // que desta vez o usuário pediu. Se os dois fossem silenciosos, o teste acima
        // passaria pelo motivo errado.
        $response = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CHANNEL.'/join', ['authenticatedAs' => self::REPLIER])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertTrue($this->isMember(self::REPLIER));
        $this->assertSame(['user_joined'], $this->systemKeys());
    }
}
