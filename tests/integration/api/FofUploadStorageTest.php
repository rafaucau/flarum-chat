<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Group\Group;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use FoF\Upload\Events\Adapter\Collecting;
use FoF\Upload\Events\Adapter\Instantiate;
use Laminas\Diactoros\UploadedFile;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Http\Message\ResponseInterface;
use Ramon\Chat\Console\PrivatizePendingUploadsCommand;
use Ramon\Chat\Console\PruneChatCommand;
use Ramon\Chat\Storage\FofUploadStore;
use Ramon\Chat\Tests\Fixtures\FakeBucketAdapter;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Anexos públicos guardados pelo fof/upload; privados, nunca.
 *
 * Dois adapters. `chat-bucket` é falso, registrado pelos eventos de adapter do
 * próprio fof/upload: grava num diretório temporário e gera URLs num host
 * próprio, o formato de um bucket S3 atrás de CDN, sem rede. Os caminhos que
 * leem o arquivo de volta (mover para o disco privado, servir durante a mudança)
 * usam o adapter `local` real, porque a leitura passa pelo downloader do
 * fof/upload, que busca por HTTP tudo que não é `local`.
 *
 * As asserções importantes são feitas no disco e no banco além da API: a API
 * pode dizer "guardado em outro lugar" sobre um arquivo que continua no webroot.
 */
class FofUploadStorageTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use ResetsVisibilityScopers;

    /** BCrypt de "too-obscure", o mesmo hash que o `normalUser()` traz. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const MEMBER = 3;
    private const OUTSIDER = 4;

    private const CH_PUBLIC = 1;
    private const CH_PRIVATE = 2;

    /** Um PNG 1×1 transparente. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** Onde o bucket falso guarda os bytes no teste atual. */
    private static string $bucketRoot = '';

    /** Faz o bucket falso recusar toda escrita. */
    private static bool $bucketDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FofUploadStore::supported()) {
            $this->markTestSkipped('fof/upload is not installed; composer install with dev dependencies.');
        }

        $this->resetVisibilityScopers();

        self::$bucketRoot = sys_get_temp_dir().'/chat-bucket-'.bin2hex(random_bytes(6));
        self::$bucketDown = false;
        mkdir(self::$bucketRoot);

        $this->extension('fof-upload', 'ramon-chat');

        // O bucket falso, registrado como qualquer adapter de terceiro do fof/upload:
        // anunciado no Collecting, montado no Instantiate.
        $this->extend(
            (new Extend\Event())
                ->listen(Collecting::class, function (Collecting $event) {
                    $event->adapters->put('chat-bucket', true);
                })
                ->listen(Instantiate::class, function (Instantiate $event) {
                    if ($event->adapter !== 'chat-bucket') {
                        return null;
                    }

                    $adapter = new FakeBucketAdapter(
                        new LocalFilesystemAdapter(self::$bucketRoot),
                        resolve(SettingsRepositoryInterface::class),
                        resolve(UrlGenerator::class)
                    );
                    $adapter->refuse = self::$bucketDown;

                    return $adapter;
                })
        );

        // Antes do boot: o repositório de settings os lê uma vez, quando o app sobe,
        // e não vê gravações posteriores na tabela. Testes que precisam de outro valor
        // chamam setting() de novo antes da primeira requisição.
        $this->setting('ramon-chat.upload_storage', 'fof-upload');
        $this->setting('ramon-chat.fof_upload_adapter', 'chat-bucket');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => self::MEMBER, 'username' => 'member', 'password' => self::PASSWORD_HASH, 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
                ['id' => self::OUTSIDER, 'username' => 'outsider', 'password' => self::PASSWORD_HASH, 'email' => 'outsider@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.upload'],
            ],
            'chat_channels' => [
                ['id' => self::CH_PUBLIC, 'type' => 'category', 'name' => 'open', 'slug' => 'open', 'status' => 'open', 'is_private' => 0, 'created_at' => $now, 'updated_at' => $now],
                ['id' => self::CH_PRIVATE, 'type' => 'category', 'name' => 'secret', 'slug' => 'secret', 'status' => 'open', 'is_private' => 1, 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => self::CH_PUBLIC, 'user_id' => self::MEMBER, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
                ['channel_id' => self::CH_PRIVATE, 'user_id' => self::MEMBER, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeTree(self::$bucketRoot);

        parent::tearDown();
    }

    public function test_a_public_file_goes_to_the_bucket_and_nowhere_else(): void
    {
        $before = $this->countFiles($this->publicRoot());

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $row = $this->row((int) $upload['id']);

        $this->assertSame('fof', $row->storage);
        $this->assertSame('chat-bucket', $row->storage_adapter);
        $this->assertSame(0, (int) $row->is_private);
        $this->assertFileExists(self::$bucketRoot.'/'.$row->path);
        $this->assertSame(base64_decode(self::PNG), file_get_contents(self::$bucketRoot.'/'.$row->path));

        // Fora do disco público do chat, por caminho e por contagem.
        $this->assertFileDoesNotExist($this->publicRoot().'/'.$row->path);
        $this->assertSame($before, $this->countFiles($this->publicRoot()));

        // A URL entregue é a do bucket.
        $this->assertSame($row->remote_url, $upload['attributes']['url']);
        $this->assertStringStartsWith(FakeBucketAdapter::HOST.'/', $upload['attributes']['url']);

        // E o fof/upload não sabe dele, então a limpeza de órfãos não o leva.
        $this->assertSame(0, $this->database()->table('fof_upload_files')->count());
    }

    public function test_a_private_file_stays_on_the_private_disk_whatever_is_configured(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PRIVATE);
        $row = $this->row((int) $upload['id']);

        $this->assertSame('local', $row->storage);
        $this->assertNull($row->remote_url);
        $this->assertTrue($upload['attributes']['isPrivate']);
        $this->assertFileExists($this->privateRoot().'/'.$row->path);
        $this->assertSame(0, $this->countFiles(self::$bucketRoot));
    }

    public function test_a_bucket_that_refuses_the_write_refuses_the_upload(): void
    {
        self::$bucketDown = true;
        $before = $this->countFiles($this->publicRoot());

        $response = $this->sendUpload(self::MEMBER, self::CH_PUBLIC);

        // Recusado, e não guardado em silêncio no disco local.
        $this->assertStatus(422, $response);
        // A mensagem traduzida, ou a chave quando o boot de teste não carrega locale.
        // De todo jeito o membro não fica sabendo nada do bucket.
        $this->assertMatchesRegularExpression('/upload_storage_failed|could not be stored/', (string) $response->getBody());
        $this->assertStringNotContainsString('chat-bucket', (string) $response->getBody());
        $this->assertSame(0, $this->database()->table('chat_uploads')->count());
        $this->assertSame($before, $this->countFiles($this->publicRoot()));
    }

    public function test_sending_a_bucket_file_to_a_private_channel_brings_it_home(): void
    {
        $this->useFofLocalAdapter();

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $remote = $this->row((int) $upload['id']);

        $this->assertSame('fof', $remote->storage);
        $this->assertFileExists($this->fofLocalRoot().'/'.$remote->path);

        $this->sendMessage(self::MEMBER, self::CH_PRIVATE, [(int) $upload['id']]);

        $row = $this->row((int) $upload['id']);

        $this->assertSame('local', $row->storage);
        $this->assertSame(1, (int) $row->is_private);
        $this->assertNull($row->remote_url);
        $this->assertNull($row->storage_adapter);
        $this->assertSame(base64_decode(self::PNG), file_get_contents($this->privateRoot().'/'.$row->path));
        $this->assertFileDoesNotExist($this->fofLocalRoot().'/'.$remote->path);
    }

    public function test_a_send_that_fails_after_moving_a_bucket_file_leaves_the_bucket_copy(): void
    {
        $this->useFofLocalAdapter();

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $remote = $this->row((int) $upload['id']);

        // Falha logo depois de a linha passar a apontar para a cópia local,
        // ainda dentro da transação do envio.
        $this->app()->getContainer()->make('events')->listen('eloquent.saved: '.\Ramon\Chat\Upload::class, function ($row) {
            if ($row->storage === 'local') {
                throw new \RuntimeException('falha simulada');
            }
        });

        $privateFiles = fn () => glob($this->privateRoot().'/*/*/*') ?: [];
        $before = $privateFiles();

        $response = $this->send($this->request('POST', '/api/chat-messages', [
            'authenticatedAs' => self::MEMBER,
            'json'            => ['data' => ['type' => 'chat-messages', 'attributes' => ['channelId' => self::CH_PRIVATE, 'content' => 'x', 'uploadIds' => [(int) $upload['id']]]]],
        ]));

        $this->assertSame(500, $response->getStatusCode());

        // A linha voltou a apontar para o bucket, e o arquivo continua lá.
        $row = $this->row((int) $upload['id']);
        $this->assertSame('fof', $row->storage);
        $this->assertSame($remote->path, $row->path);
        $this->assertFileExists($this->fofLocalRoot().'/'.$remote->path);
        $this->assertSame($before, $privateFiles(), 'sem cópia local órfã');
    }

    public function test_making_a_channel_private_brings_its_bucket_files_home(): void
    {
        $this->useFofLocalAdapter();

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $remote = $this->row((int) $upload['id']);
        $this->sendMessage(self::MEMBER, self::CH_PUBLIC, [(int) $upload['id']]);

        $response = $this->send(
            $this->request('PATCH', '/api/chat-channels/'.self::CH_PUBLIC, [
                'authenticatedAs' => 1,
                'json'            => [
                    'data' => [
                        'type'       => 'chat-channels',
                        'id'         => (string) self::CH_PUBLIC,
                        'attributes' => ['isPrivate' => true],
                    ],
                ],
            ])
        );

        $this->assertStatus(200, $response);

        // A fila é síncrona aqui, então o job já rodou.
        $row = $this->row((int) $upload['id']);

        $this->assertSame('local', $row->storage);
        $this->assertSame(1, (int) $row->is_private);
        $this->assertFileExists($this->privateRoot().'/'.$row->path);
        $this->assertFileDoesNotExist($this->fofLocalRoot().'/'.$remote->path);
    }

    public function test_a_file_flagged_private_while_still_remote_is_served_only_through_the_check(): void
    {
        $this->useFofLocalAdapter();

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);

        // O estado entre marcar e mover, que o job deixa se a mudança falhar.
        $this->database()->table('chat_uploads')->where('id', $upload['id'])->update(['is_private' => 1]);

        $served = $this->fetch((int) $upload['id'], self::MEMBER);

        $this->assertStatus(200, $served);
        $this->assertSame(base64_decode(self::PNG), (string) $served->getBody());
        $this->assertSame('image/png', $served->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $served->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringStartsWith('private', $served->getHeaderLine('Cache-Control'));
        $this->assertSame('', $served->getHeaderLine('Accept-Ranges'));

        $this->assertStatus(404, $this->fetch((int) $upload['id'], self::OUTSIDER));
    }

    public function test_a_move_the_job_gave_up_on_is_finished_by_the_hourly_command(): void
    {
        $this->useFofLocalAdapter();

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $remote = $this->row((int) $upload['id']);
        $this->sendMessage(self::MEMBER, self::CH_PUBLIC, [(int) $upload['id']]);

        // Marcado, bytes ainda no bucket: o que três tentativas falhas deixam.
        $this->database()->table('chat_uploads')->where('id', $upload['id'])->update(['is_private' => 1]);

        $command = $this->app()->getContainer()->make(PrivatizePendingUploadsCommand::class);
        $command->run(new ArrayInput([]), new BufferedOutput());

        $row = $this->row((int) $upload['id']);

        $this->assertSame('local', $row->storage);
        $this->assertSame(1, (int) $row->is_private);
        $this->assertSame(base64_decode(self::PNG), file_get_contents($this->privateRoot().'/'.$row->path));
        $this->assertFileDoesNotExist($this->fofLocalRoot().'/'.$remote->path);
    }

    public function test_a_public_bucket_file_is_not_proxied_by_the_forum(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);

        $this->assertStatus(404, $this->fetch((int) $upload['id'], self::MEMBER));
    }

    public function test_deleting_a_message_deletes_its_bucket_file(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $path = $this->row((int) $upload['id'])->path;
        $messageId = $this->sendMessage(self::MEMBER, self::CH_PUBLIC, [(int) $upload['id']]);

        $this->assertFileExists(self::$bucketRoot.'/'.$path);

        $response = $this->send(
            $this->request('POST', '/api/chat-messages/'.$messageId.'/delete', ['authenticatedAs' => self::MEMBER])
        );

        $this->assertStatus(204, $response);
        $this->assertFileDoesNotExist(self::$bucketRoot.'/'.$path);
        $this->assertSame(0, $this->database()->table('chat_uploads')->count());
    }

    public function test_discarding_a_pending_upload_deletes_its_bucket_file(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $path = $this->row((int) $upload['id'])->path;

        $response = $this->send(
            $this->request('DELETE', '/api/chat-uploads/'.$upload['id'], ['authenticatedAs' => self::MEMBER])
        );

        $this->assertStatus(204, $response);
        $this->assertFileDoesNotExist(self::$bucketRoot.'/'.$path);
        $this->assertSame(0, $this->database()->table('chat_uploads')->count());
    }

    public function test_discarding_a_pending_local_upload_deletes_its_file_too(): void
    {
        // Vale também sem fof/upload: a linha sai e o arquivo precisa sair junto.
        $this->setting('ramon-chat.upload_storage', 'local');

        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $path = $this->row((int) $upload['id'])->path;

        $this->assertFileExists($this->publicRoot().'/'.$path);

        $response = $this->send(
            $this->request('DELETE', '/api/chat-uploads/'.$upload['id'], ['authenticatedAs' => self::MEMBER])
        );

        $this->assertStatus(204, $response);
        $this->assertFileDoesNotExist($this->publicRoot().'/'.$path);
    }

    public function test_pruning_an_orphaned_upload_deletes_its_bucket_file(): void
    {
        $upload = $this->upload(self::MEMBER, self::CH_PUBLIC);
        $path = $this->row((int) $upload['id'])->path;

        $this->database()->table('chat_uploads')->where('id', $upload['id'])
            ->update(['created_at' => Carbon::now()->subDays(2)->toDateTimeString()]);

        $command = $this->app()->getContainer()->make(PruneChatCommand::class);
        $command->run(new ArrayInput([]), new BufferedOutput());

        $this->assertFileDoesNotExist(self::$bucketRoot.'/'.$path);
        $this->assertSame(0, $this->database()->table('chat_uploads')->count());
    }

    // ── Helpers ──

    /**
     * O adapter local real do fof/upload, para os caminhos que leem o arquivo de volta.
     */
    private function useFofLocalAdapter(): void
    {
        $this->setting('ramon-chat.fof_upload_adapter', 'local');
    }

    /**
     * @return array{id: string, attributes: array<string, mixed>}
     */
    private function upload(int $as, int $channelId): array
    {
        $response = $this->sendUpload($as, $channelId);

        $this->assertStatus(201, $response);

        return json_decode((string) $response->getBody(), true)['data'];
    }

    private function sendUpload(int $as, int $channelId): ResponseInterface
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chat');
        file_put_contents($tmp, base64_decode(self::PNG));

        return $this->send(
            $this->request('POST', '/api/chat/uploads', ['authenticatedAs' => $as])
                ->withUploadedFiles(['file' => new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, 'pic.png', 'image/png')])
                ->withParsedBody(['channelId' => (string) $channelId])
        );
    }

    /**
     * @param  int[]  $uploadIds
     */
    private function sendMessage(int $as, int $channelId, array $uploadIds): int
    {
        $response = $this->send(
            $this->request('POST', '/api/chat-messages', [
                'authenticatedAs' => $as,
                'json'            => [
                    'data' => [
                        'type'       => 'chat-messages',
                        'attributes' => [
                            'channelId' => $channelId,
                            'content'   => 'here you go',
                            'uploadIds' => $uploadIds,
                        ],
                    ],
                ],
            ])
        );

        $this->assertStatus(201, $response);

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }

    private function fetch(int $uploadId, ?int $as): ResponseInterface
    {
        return $this->send(
            $this->request('GET', '/api/chat/uploads/'.$uploadId.'/file', $as ? ['authenticatedAs' => $as] : [])
                ->withQueryParams(['id' => (string) $uploadId])
        );
    }

    /**
     * O corpo vai junto quando o status não bate: um 500 só se diagnostica por ele.
     */
    private function assertStatus(int $expected, ResponseInterface $response): void
    {
        $this->assertEquals($expected, $response->getStatusCode(), substr((string) $response->getBody(), 0, 2000));
    }

    private function row(int $uploadId): object
    {
        return $this->database()->table('chat_uploads')->where('id', $uploadId)->first();
    }

    private function publicRoot(): string
    {
        return $this->app()->getContainer()->make(Paths::class)->public.'/assets/chat';
    }

    private function privateRoot(): string
    {
        return $this->app()->getContainer()->make(Paths::class)->storage.'/chat-uploads';
    }

    private function fofLocalRoot(): string
    {
        return $this->app()->getContainer()->make(Paths::class)->public.'/assets/files';
    }

    private function countFiles(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $count = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $item) {
            $count += $item->isFile() ? 1 : 0;
        }

        return $count;
    }

    private function removeTree(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
