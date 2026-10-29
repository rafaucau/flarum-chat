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
 * Cada ator contra cada tipo de canal, por cada porta.
 *
 * Quatro atores × quatro canais × quatro operações (ser listado, lido, entrado
 * e receber mensagem), conferidos como uma grade só porque os bugs que
 * importam são divergências *entre* elas: canal que lista mas não abre, que
 * deixa entrar mas não falar, que o escopo esconde e a policy deixaria passar.
 *
 * A grade também prova que nenhuma permissão é chave-mestra: três linhas têm
 * alguma permissão de chat e nenhuma alcança canal restrito por *tag*.
 * `accessPrivateChannels` abrindo uma categoria restrita seria um buraco
 * silencioso nas permissões do fórum.
 *
 * Processo próprio pelo motivo descrito em TagBoundChannelTest: o registro
 * estático `ScopeVisibilityTrait::$visibilityScopers` sobrevive entre boots.
 */
#[RunTestsInSeparateProcesses]
class ChannelAccessMatrixTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt de "too-obscure", o mesmo hash que o `normalUser()` traz. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const ADMIN = 1;
    private const PLAIN = 2;
    private const ACCESS = 3;
    private const MODERATOR = 4;

    private const CH_PUBLIC = 1;
    private const CH_PRIVATE = 2;
    private const CH_RESTRICTED = 3;
    private const CH_DIRECT = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                $this->user(self::ACCESS, 'accessor'),
                $this->user(self::MODERATOR, 'moderator'),
                // As duas pontas do canal direto. Ninguém da matriz é uma delas, e esse é o
                // ponto: DM não se alcança por permissão.
                $this->user(5, 'dmalice'),
                $this->user(6, 'dmbob'),
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Accessor', 'name_plural' => 'Accessors'],
                ['id' => 101, 'name_singular' => 'Chatmod', 'name_plural' => 'Chatmods'],
                // Tem a tag restrita. Vazio de propósito: a coluna restrita precisa ficar
                // inalcançável para todo ator da grade menos o admin.
                ['id' => 102, 'name_singular' => 'Tagholder', 'name_plural' => 'Tagholders'],
            ],
            'group_user' => [
                ['user_id' => self::ACCESS, 'group_id' => 100],
                ['user_id' => self::MODERATOR, 'group_id' => 101],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => 100, 'permission' => 'ramon-chat.accessPrivateChannels'],
                ['group_id' => 101, 'permission' => 'ramon-chat.moderate'],
                // Entrar oculto é um direito próprio; o moderador aqui também o tem, como a
                // migration de upgrade dá aos grupos de moderação.
                ['group_id' => 101, 'permission' => 'ramon-chat.inspectChannels'],
                ['group_id' => 102, 'permission' => 'tag2.viewForum'],
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Lounge', 'slug' => 'lounge', 'position' => 0, 'is_restricted' => 0],
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => 1],
            ],
            'chat_channels' => [
                $this->category(self::CH_PUBLIC, 'open-chat', tagId: null, private: false),
                $this->category(self::CH_PRIVATE, 'private-chat', tagId: null, private: true),
                $this->category(self::CH_RESTRICTED, 'staff-chat', tagId: 2, private: false),
                $this->direct(self::CH_DIRECT),
            ],
            'chat_channel_user' => [
                $this->membership(self::CH_DIRECT, 5),
                $this->membership(self::CH_DIRECT, 6),
            ],
            // Uma mensagem por canal, para as leituras abaixo perguntarem por conteúdo
            // que existe. Canal vazio responderia "[]" pelo motivo certo e pelo errado.
            'chat_messages' => [
                $this->message(10, self::CH_PUBLIC),
                $this->message(20, self::CH_PRIVATE),
                $this->message(30, self::CH_RESTRICTED),
                $this->message(40, self::CH_DIRECT),
            ],
            // Pelo mesmo motivo, tópico, pin e denúncia existem nos quatro canais: cada
            // um é outra saída para o conteúdo.
            'chat_threads' => [
                $this->thread(11, self::CH_PUBLIC, 10),
                $this->thread(21, self::CH_PRIVATE, 20),
                $this->thread(31, self::CH_RESTRICTED, 30),
                $this->thread(41, self::CH_DIRECT, 40),
            ],
            'chat_message_flags' => [
                $this->flag(12, 10),
                $this->flag(22, 20),
                $this->flag(32, 30),
                $this->flag(42, 40),
            ],
        ]);

        // Fixar os quatro faz o filtro de fixadas perguntar o mesmo que a listagem.
        // Só `pinned_at` decide; `pinned_by_id` é só atribuição.
        $this->database()->table('chat_messages')->update([
            'pinned_at'    => Carbon::now()->toDateTimeString(),
            'pinned_by_id' => 6,
        ]);
    }

    // ── Montagem da fixture ──

    /** @return array<string, mixed> */
    private function user(int $id, string $username): array
    {
        return [
            'id'                 => $id,
            'username'           => $username,
            'password'           => self::PASSWORD_HASH,
            'email'              => $username.'@machine.local',
            'is_email_confirmed' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function category(int $id, string $slug, ?int $tagId, bool $private): array
    {
        return [
            'id'         => $id,
            'type'       => 'category',
            'name'       => $slug,
            'slug'       => $slug,
            'status'     => 'open',
            'tag_id'     => $tagId,
            'is_private' => $private ? 1 : 0,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function direct(int $id): array
    {
        return [
            'id'         => $id,
            'type'       => 'direct',
            'name'       => null,
            'slug'       => null,
            'status'     => 'open',
            'tag_id'     => null,
            'is_private' => 0,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function message(int $id, int $channelId): array
    {
        return [
            'id'         => $id,
            'channel_id' => $channelId,
            'user_id'    => 5,
            'type'       => 'text',
            // XML válido do TextFormatter: `content` passa pelo mesmo unparse/render de
            // uma mensagem real, e texto puro faz o renderer lançar exceção em vez de o
            // endpoint responder.
            'content'    => '<t><p>secret of channel '.$channelId.'</p></t>',
            'number'     => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function thread(int $id, int $channelId, int $rootMessageId): array
    {
        return [
            'id'                  => $id,
            'channel_id'          => $channelId,
            'original_message_id' => $rootMessageId,
            'title'               => 'thread of channel '.$channelId,
            'creator_id'          => 5,
            'status'              => 'open',
            'created_at'          => Carbon::now()->toDateTimeString(),
            'updated_at'          => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function flag(int $id, int $messageId): array
    {
        return [
            'id'         => $id,
            'message_id' => $messageId,
            'user_id'    => 6,
            'reason'     => 'spam',
            'detail'     => 'flag detail of message '.$messageId,
            'created_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function membership(int $channelId, int $userId): array
    {
        return [
            'channel_id' => $channelId,
            'user_id'    => $userId,
            'joined_at'  => Carbon::now()->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    // ── As quatro portas ──

    /** @return int[] */
    private function listed(int $as): array
    {
        $response = $this->send($this->request('GET', '/api/chat-channels', ['authenticatedAs' => $as]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);
        $ids = array_map(static fn (array $row) => (int) $row['id'], $body['data'] ?? []);

        sort($ids);

        return $ids;
    }

    private function showStatus(int $as, int $channel): int
    {
        return $this->send(
            $this->request('GET', '/api/chat-channels/'.$channel, ['authenticatedAs' => $as])
        )->getStatusCode();
    }

    private function joinStatus(int $as, int $channel): int
    {
        return $this->send(
            $this->request('POST', '/api/chat-channels/'.$channel.'/join', ['authenticatedAs' => $as])
        )->getStatusCode();
    }

    private function postStatus(int $as, int $channel): int
    {
        return $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => $as,
                'json'            => ['data' => ['attributes' => [
                    'channelId' => $channel,
                    'content'   => 'matrix probe',
                ]]],
            ])
        )->getStatusCode();
    }

    /**
     * @return array<int, string> id do ator => rótulo, para as mensagens de asserção
     */
    private static function actors(): array
    {
        return [
            self::PLAIN     => 'plain member',
            self::ACCESS    => 'member with accessPrivateChannels',
            self::MODERATOR => 'chat moderator',
            self::ADMIN     => 'administrator',
        ];
    }

    // ── 1: o que a listagem mostra ──

    public function test_listing_is_scoped_per_actor(): void
    {
        // Canal direto não aparece para ninguém aqui, nem moderador nem admin: a
        // membership é a única chave.
        $this->assertSame([self::CH_PUBLIC], $this->listed(self::PLAIN), 'plain member');
        $this->assertSame([self::CH_PUBLIC, self::CH_PRIVATE], $this->listed(self::ACCESS), 'accessPrivateChannels');
        $this->assertSame([self::CH_PUBLIC, self::CH_PRIVATE], $this->listed(self::MODERATOR), 'moderator');
        $this->assertSame(
            [self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED],
            $this->listed(self::ADMIN),
            'administrator'
        );
    }

    // ── 2: buscar um diretamente ──

    public function test_show_agrees_with_the_listing(): void
    {
        $expected = [
            self::PLAIN     => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 404, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::ACCESS    => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 200, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::MODERATOR => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 200, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::ADMIN     => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 200, self::CH_RESTRICTED => 200, self::CH_DIRECT => 404],
        ];

        foreach ($expected as $actor => $channels) {
            foreach ($channels as $channel => $status) {
                $this->assertSame(
                    $status,
                    $this->showStatus($actor, $channel),
                    sprintf('GET channel %d as %s', $channel, self::actors()[$actor])
                );
            }
        }
    }

    // ── 3: entrar por conta própria ──

    public function test_join_matches_what_can_be_seen(): void
    {
        // 200 deixa; 404 é a recusa para canal que o ator não vê. 404 e não 403 de
        // propósito: a rota de entrada é model-scoped, então o canal passa pelo mesmo
        // escopo da listagem e simplesmente não existe. Um 403 confirmaria que há um
        // canal privado com aquele id.
        //
        // Não há célula visível e recusada: ver um canal e poder entrar são a mesma
        // resposta. As recusas estruturais são conferidas à parte abaixo.
        $expected = [
            self::PLAIN     => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 404, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::ACCESS    => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 200, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::MODERATOR => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 200, self::CH_RESTRICTED => 404, self::CH_DIRECT => 404],
            self::ADMIN     => [self::CH_PUBLIC => 200, self::CH_PRIVATE => 200, self::CH_RESTRICTED => 200, self::CH_DIRECT => 404],
        ];

        foreach ($expected as $actor => $channels) {
            foreach ($channels as $channel => $status) {
                $this->assertSame(
                    $status,
                    $this->joinStatus($actor, $channel),
                    sprintf('JOIN channel %d as %s', $channel, self::actors()[$actor])
                );
            }
        }
    }

    public function test_a_direct_channel_cannot_be_joined_even_by_a_participant(): void
    {
        // A única recusa que não é de visibilidade: o usuário 5 está no canal direto
        // e o vê, então a policy é consultada e diz não, porque conversa a dois se
        // entra por convite, nunca por conta própria. Visível e mesmo assim recusado.
        $this->assertSame(403, $this->joinStatus(5, self::CH_DIRECT));
    }

    // ── 4: falar, depois de entrar ──

    public function test_posting_requires_the_join_and_follows_it(): void
    {
        // Cada célula é perguntada duas vezes, antes e depois de entrar. Escrever exige
        // membership mesmo onde ler não exige, então a primeira resposta nunca é
        // sucesso; onde a entrada é recusada, a segunda continua recusada.
        //
        // Dois códigos de recusa porque o id do canal vem no corpo, não no path: canal
        // invisível falha em MessageResource::findChannel com 422 em `channelId`, e um
        // visível sem membership falha na policy com 403. Nenhum distingue "não existe"
        // de "não é para você" na mensagem.
        $before = [
            self::PLAIN     => [self::CH_PUBLIC => 403, self::CH_PRIVATE => 422, self::CH_RESTRICTED => 422, self::CH_DIRECT => 422],
            self::ACCESS    => [self::CH_PUBLIC => 403, self::CH_PRIVATE => 403, self::CH_RESTRICTED => 422, self::CH_DIRECT => 422],
            self::MODERATOR => [self::CH_PUBLIC => 403, self::CH_PRIVATE => 403, self::CH_RESTRICTED => 422, self::CH_DIRECT => 422],
            self::ADMIN     => [self::CH_PUBLIC => 403, self::CH_PRIVATE => 403, self::CH_RESTRICTED => 403, self::CH_DIRECT => 422],
        ];

        foreach ($before as $actor => $channels) {
            foreach ($channels as $channel => $status) {
                $label = self::actors()[$actor];

                $this->assertSame(
                    $status,
                    $this->postStatus($actor, $channel),
                    sprintf('POST before joining channel %d as %s', $channel, $label)
                );

                $joined = $this->joinStatus($actor, $channel) === 200;

                $this->assertSame(
                    $joined ? 201 : $status,
                    $this->postStatus($actor, $channel),
                    sprintf('POST after %s channel %d as %s', $joined ? 'joining' : 'failing to join', $channel, $label)
                );
            }
        }
    }

    // ── 5: ler o que tem dentro ──

    /**
     * A direção que leva conteúdo, e a que um gate só no canal perderia: o cliente
     * não precisa passar por `/chat-channels` para chegar a uma mensagem. Pode
     * filtrar o índice por id de canal ou pedir a mensagem pelo id, e os dois
     * precisam responder como o canal responderia.
     */
    public function test_messages_are_unreachable_wherever_the_channel_is(): void
    {
        // Os ids de mensagem são o id do canal × 10 (ver a fixture).
        $expected = [
            self::PLAIN     => [self::CH_PUBLIC],
            self::ACCESS    => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::MODERATOR => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::ADMIN     => [self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED],
        ];

        foreach ($expected as $actor => $readable) {
            $label = self::actors()[$actor];

            foreach ([self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED, self::CH_DIRECT] as $channel) {
                $allowed = in_array($channel, $readable, true);

                // Filtrar o índice por um canal que o ator não vê volta vazio, não com o
                // conteúdo.
                $this->assertSame(
                    $allowed ? ['secret of channel '.$channel] : [],
                    $this->messageContentsIn($actor, $channel),
                    sprintf('LIST messages of channel %d as %s', $channel, $label)
                );

                // E a mensagem não pode ser buscada pelo id contornando o filtro.
                $this->assertSame(
                    $allowed ? 200 : 404,
                    $this->showMessageStatus($actor, $channel * 10),
                    sprintf('GET message %d as %s', $channel * 10, $label)
                );
            }
        }
    }

    public function test_search_does_not_reach_across_the_scope(): void
    {
        // A busca full-text é outro caminho para as mesmas linhas, e a palavra
        // buscada aparece na mensagem de todo canal.
        $this->assertSame(
            ['secret of channel '.self::CH_PUBLIC],
            $this->searchContents(self::PLAIN, 'secret'),
            'a plain member finds only what they could already read'
        );

        $this->assertSame(
            ['secret of channel '.self::CH_PUBLIC, 'secret of channel '.self::CH_PRIVATE],
            $this->searchContents(self::ACCESS, 'secret'),
            'the permission widens search exactly as far as it widens the listing'
        );
    }

    /** @return string[] */
    private function messageContentsIn(int $as, int $channel): array
    {
        // `withQueryParams`, não query string no path: o harness monta a requisição
        // com `new ServerRequest([], [], $path, $method)` e não lê a query da URI
        // (o Diactoros não faz isso sozinho). Um filtro no path seria descartado e o
        // endpoint responderia sem filtro, o que pareceria um vazamento de escopo.
        $response = $this->send(
            $this->request('GET', '/api/chat-messages', ['authenticatedAs' => $as])
                ->withQueryParams(['filter' => ['channel' => (string) $channel]])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->contentsOf($response);
    }

    /** @return string[] */
    private function searchContents(int $as, string $query): array
    {
        $response = $this->send(
            $this->request('GET', '/api/chat-messages', ['authenticatedAs' => $as])
                ->withQueryParams(['filter' => ['q' => $query]])
        );

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $contents = $this->contentsOf($response);
        sort($contents);

        return $contents;
    }

    /** @return string[] */
    private function contentsOf(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return array_values(array_filter(array_map(
            static fn (array $row) => $row['attributes']['content'] ?? null,
            $body['data'] ?? []
        )));
    }

    private function showMessageStatus(int $as, int $message): int
    {
        return $this->send(
            $this->request('GET', '/api/chat-messages/'.$message, ['authenticatedAs' => $as])
        )->getStatusCode();
    }

    // ── 6: as outras saídas de conteúdo de um canal ──

    /**
     * Tópicos levam os títulos e a estrutura de respostas de um canal, com
     * resource, filtro e escopo próprios. Um gate que cobrisse só mensagens
     * deixaria a forma de uma conversa privada sair por aqui.
     */
    public function test_threads_are_unreachable_wherever_the_channel_is(): void
    {
        $expected = [
            self::PLAIN     => [self::CH_PUBLIC],
            self::ACCESS    => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::MODERATOR => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::ADMIN     => [self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED],
        ];

        foreach ($expected as $actor => $readable) {
            $label = self::actors()[$actor];

            // O índice sem filtro é a pergunta mais ampla: tudo que o ator alcança, sem
            // canal nomeado.
            $this->assertSame(
                array_map(static fn (int $c) => 'thread of channel '.$c, $readable),
                $this->threadTitles($actor, null),
                sprintf('LIST every thread as %s', $label)
            );

            foreach ([self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED, self::CH_DIRECT] as $channel) {
                $allowed = in_array($channel, $readable, true);

                $this->assertSame(
                    $allowed ? ['thread of channel '.$channel] : [],
                    $this->threadTitles($actor, $channel),
                    sprintf('LIST threads of channel %d as %s', $channel, $label)
                );

                // Os ids de tópico são canal × 10 + 1 (ver a fixture).
                $this->assertSame(
                    $allowed ? 200 : 404,
                    $this->send(
                        $this->request('GET', '/api/chat-threads/'.($channel * 10 + 1), ['authenticatedAs' => $actor])
                    )->getStatusCode(),
                    sprintf('GET thread %d as %s', $channel * 10 + 1, $label)
                );
            }
        }
    }

    /**
     * O painel de fixadas é uma segunda listagem das mesmas linhas com outro
     * filtro, então é uma segunda chance de esquecer o escopo.
     */
    public function test_the_pinned_filter_does_not_widen_the_scope(): void
    {
        $expected = [
            self::PLAIN     => [self::CH_PUBLIC],
            self::ACCESS    => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::MODERATOR => [self::CH_PUBLIC, self::CH_PRIVATE],
            self::ADMIN     => [self::CH_PUBLIC, self::CH_PRIVATE, self::CH_RESTRICTED],
        ];

        foreach ($expected as $actor => $readable) {
            $response = $this->send(
                $this->request('GET', '/api/chat-messages', ['authenticatedAs' => $actor])
                    ->withQueryParams(['filter' => ['pinned' => '1']])
            );

            $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

            $contents = $this->contentsOf($response);
            sort($contents);

            $this->assertSame(
                array_map(static fn (int $c) => 'secret of channel '.$c, $readable),
                $contents,
                sprintf('LIST pinned as %s', self::actors()[$actor])
            );
        }
    }

    /**
     * A fila de moderação tem permissão própria, que não pode ampliar o que mostra:
     * a denúncia expõe a mensagem, então a fila fica dentro dos canais que o
     * moderador já lê.
     */
    public function test_the_flag_queue_is_moderator_only_and_still_channel_scoped(): void
    {
        // Recusado no endpoint em vez de lista vazia: o Index checa
        // `can('ramon-chat.moderate')` antes de montar a query. Ter
        // `accessPrivateChannels` não muda nenhuma resposta: ler um canal privado não
        // é moderá-lo.
        foreach ([self::PLAIN, self::ACCESS] as $actor) {
            $this->assertSame(
                403,
                $this->send($this->request('GET', '/api/chat-message-flags', ['authenticatedAs' => $actor]))
                    ->getStatusCode(),
                sprintf('the queue refuses %s', self::actors()[$actor])
            );
        }

        // O moderador vê as denúncias dos canais que lê (público e privado), não a do
        // canal restrito por tag, que a permissão de chat não abre.
        $this->assertSame(
            ['flag detail of message 10', 'flag detail of message 20'],
            $this->flagDetails(self::MODERATOR)
        );

        $this->assertSame(
            ['flag detail of message 10', 'flag detail of message 20', 'flag detail of message 30'],
            $this->flagDetails(self::ADMIN)
        );
    }

    /**
     * A lista de membros. Não é conteúdo, mas responde "quem está naquela sala",
     * justamente o que um canal privado esconde.
     */
    public function test_the_participant_list_follows_the_channel(): void
    {
        // Pergunta sobre o canal direto, cujos participantes são os únicos da
        // fixture. Todos da matriz estão fora.
        foreach (self::actors() as $actor => $label) {
            $response = $this->send(
                $this->request('GET', '/api/chat-channels/'.self::CH_DIRECT, ['authenticatedAs' => $actor])
                    ->withQueryParams(['include' => 'participants'])
            );

            $this->assertSame(404, $response->getStatusCode(), sprintf('participants of a DM as %s', $label));
        }

        // E de dentro a mesma requisição funciona, senão a asserção acima passaria
        // num endpoint quebrado em vez de numa porta fechada.
        $response = $this->send(
            $this->request('GET', '/api/chat-channels/'.self::CH_DIRECT, ['authenticatedAs' => 5])
                ->withQueryParams(['include' => 'participants'])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);
        $usernames = array_map(
            static fn (array $row) => $row['attributes']['username'] ?? null,
            array_values(array_filter($body['included'] ?? [], static fn (array $row) => $row['type'] === 'users'))
        );

        sort($usernames);

        $this->assertSame(['dmalice', 'dmbob'], $usernames);
    }

    /** @return string[] */
    private function threadTitles(int $as, ?int $channel): array
    {
        $request = $this->request('GET', '/api/chat-threads', ['authenticatedAs' => $as]);

        if ($channel !== null) {
            $request = $request->withQueryParams(['filter' => ['channel' => (string) $channel]]);
        }

        $response = $this->send($request);

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);
        $titles = array_values(array_filter(array_map(
            static fn (array $row) => $row['attributes']['title'] ?? null,
            $body['data'] ?? []
        )));

        sort($titles);

        return $titles;
    }

    /** @return string[] */
    private function flagDetails(int $as): array
    {
        $response = $this->send($this->request('GET', '/api/chat-message-flags', ['authenticatedAs' => $as]));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true);
        $details = array_values(array_filter(array_map(
            static fn (array $row) => $row['attributes']['detail'] ?? null,
            $body['data'] ?? []
        )));

        sort($details);

        return $details;
    }

    // ── Efeitos colaterais da grade ──

    public function test_a_join_is_announced_in_the_channel(): void
    {
        $this->assertSame(200, $this->joinStatus(self::PLAIN, self::CH_PUBLIC));

        $this->assertSame(
            ['user_joined'],
            $this->systemKeysIn(self::CH_PUBLIC),
            'a deliberate join announces itself, as a departure does'
        );
    }

    public function test_a_hidden_join_and_its_departure_are_both_silent(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PUBLIC.'/join', [
                'authenticatedAs' => self::MODERATOR,
                'json'            => ['data' => ['attributes' => ['hidden' => true]]],
            ])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([], $this->systemKeysIn(self::CH_PUBLIC), 'a hidden join says nothing');

        $leave = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PUBLIC.'/leave', ['authenticatedAs' => self::MODERATOR])
        );

        $this->assertSame(204, $leave->getStatusCode(), (string) $leave->getBody());

        // Anunciar a saída de quem entrou sem anúncio contaria à sala, depois do fato,
        // que a pessoa esteve lá, justamente o que a entrada oculta evita.
        $this->assertSame([], $this->systemKeysIn(self::CH_PUBLIC), 'and neither does its ending');
    }

    public function test_a_visible_join_and_departure_are_both_announced(): void
    {
        $this->assertSame(200, $this->joinStatus(self::PLAIN, self::CH_PUBLIC));

        $leave = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PUBLIC.'/leave', ['authenticatedAs' => self::PLAIN])
        );

        $this->assertSame(204, $leave->getStatusCode(), (string) $leave->getBody());

        $this->assertSame(
            ['user_joined', 'user_left'],
            $this->systemKeysIn(self::CH_PUBLIC),
            'both halves of the same fact are narrated'
        );
    }

    public function test_being_invited_is_announced_only_once_the_invitation_is_accepted(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-channels/'.self::CH_PUBLIC.'/members', [
                'authenticatedAs' => self::MODERATOR,
                'json'            => ['data' => ['attributes' => ['userIds' => [self::PLAIN]]]],
            ])
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // Nada ainda: convite é uma pergunta, e a sala só ouve a resposta. Narrar a
        // pergunta anunciaria uma chegada que pode não acontecer.
        $this->assertSame([], $this->systemKeysIn(self::CH_PUBLIC));

        $accept = $this->send(
            $this->request('POST', '/api/chat/invites/'.self::CH_PUBLIC.'/accept', [
                'authenticatedAs' => self::PLAIN,
            ])
        );

        $this->assertSame(200, $accept->getStatusCode(), (string) $accept->getBody());

        // Não `user_joined`: a membership chega como resposta ao convite de outra
        // pessoa, e narrá-la como chegada comum não deixaria a sala distinguir convite
        // de entrada espontânea.
        $this->assertSame(['user_accepted_invite'], $this->systemKeysIn(self::CH_PUBLIC));

        // Os dois nomes, porque a frase precisa dos dois.
        $this->assertSame(
            ['actor' => 'moderator', 'username' => 'normal'],
            $this->systemDataIn(self::CH_PUBLIC)
        );
    }

    /** @return string[] */
    private function systemKeysIn(int $channel): array
    {
        return $this->database()->table('chat_messages')
            ->where('channel_id', $channel)
            ->where('type', 'system')
            ->orderBy('id')
            ->pluck('system_key')
            ->all();
    }

    /**
     * Os dados de interpolação da única linha de sistema de um canal.
     *
     * Ordenados por chave para a asserção dizer o que precisa estar lá, não a ordem
     * em que foi gravado.
     *
     * @return array<string, string>
     */
    private function systemDataIn(int $channel): array
    {
        $raw = $this->database()->table('chat_messages')
            ->where('channel_id', $channel)
            ->where('type', 'system')
            ->orderBy('id')
            ->value('system_data');

        $data = json_decode((string) $raw, true) ?: [];

        ksort($data);

        return $data;
    }
}
