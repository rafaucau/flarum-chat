<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage;

use Flarum\Settings\SettingsRepositoryInterface;
use FoF\Upload\Adapters\Flysystem;
use FoF\Upload\Adapters\Manager;
use Flarum\Foundation\Paths;
use FoF\Upload\File;
use FoF\Upload\Helpers\Util;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Ramon\Chat\Upload;

/**
 * Anexos públicos gravados pelos adapters do fof/upload, usado só como driver:
 * permissão, mimes e limites são do chat. Nunca grava `fof_upload_files`, porque
 * o cleanup do fof apaga arquivos sem post. Só adapters Flysystem (o Imgur não
 * apaga por path). Única classe que toca o namespace FoF.
 */
class FofUploadStore implements UploadStore
{
    /**
     * Segundos: curto o bastante para a request ou o job desistir em vez de travar.
     */
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT = 30;

    public function __construct(
        protected Container $container,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * Só se as classes existem; se a extensão está ligada é com o UploadStorage.
     */
    public static function supported(): bool
    {
        return class_exists(Manager::class);
    }

    /**
     * Adapters que o admin pode escolher: os disponíveis no fof/upload, menos Imgur.
     *
     * @return string[]
     */
    public function adapterKeys(): array
    {
        return $this->manager()->adapters()
            ->filter(fn ($available) => (bool) $available)
            ->keys()
            ->reject(fn ($key) => $key === 'imgur')
            ->values()
            ->all();
    }

    public function put(Upload $upload, $stream, string $extension): void
    {
        $adapter = $this->adapterFor((string) $upload->mime_type);

        $file = $this->file($upload);
        // Gerado no servidor: o nome do cliente nunca vira path. O adapter
        // acrescenta data e timestamp.
        $file->base_name = Str::random(28).'.'.$extension;

        try {
            $result = $adapter->upload($file, null, $stream);
        } catch (\Throwable $e) {
            throw new StorageFailed('fof/upload adapter failed: '.$e->getMessage(), 0, $e);
        }

        if ($result === false || ! $file->path || ! $file->url) {
            throw new StorageFailed("fof/upload adapter {$adapter->adapterKey} refused the write.");
        }

        $upload->path = (string) $file->path;
        $upload->storage = Upload::STORAGE_FOF;
        $upload->storage_adapter = $adapter->adapterKey;
        $upload->remote_url = (string) $file->url;
    }

    public function delete(Upload $upload): void
    {
        $adapter = $this->adapterOf($upload);

        // O Flysystem não reclama de arquivo ausente; false só quando o backend
        // recusou.
        if ($adapter->delete($this->file($upload)) === false) {
            throw new StorageFailed("fof/upload adapter {$adapter->adapterKey} could not delete {$upload->path}.");
        }
    }

    /**
     * Do disco no adapter local, por HTTP nos demais, sem o downloader do
     * fof/upload (o cliente dele não tem timeout). A URL vem da linha, então só é
     * buscada se o host bate com o que o adapter geraria hoje: barra SSRF.
     */
    public function readStream(Upload $upload)
    {
        if ($upload->storage_adapter === 'local') {
            return $this->readLocal($upload);
        }

        try {
            $url = $this->trustedUrl($upload);
        } catch (\Throwable $e) {
            return null;
        }

        return $url === null ? null : $this->fetch($url, $upload);
    }

    public function size(Upload $upload): ?int
    {
        return null;
    }

    /**
     * O adapter local grava em `public/assets/files`. A leitura fica confinada ao
     * diretório porque o path vem da linha.
     *
     * @return resource|null
     */
    protected function readLocal(Upload $upload)
    {
        $base = realpath($this->container->make(Paths::class)->public.'/assets/files');
        $relative = (string) $upload->path;

        if ($base === false || $relative === '' || str_contains($relative, "\0") || str_contains($relative, '://')) {
            return null;
        }

        $resolved = realpath($base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\'));

        if ($resolved === false
            || ! is_file($resolved)
            || ! str_starts_with($resolved.DIRECTORY_SEPARATOR, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $stream = fopen($resolved, 'rb');

        return is_resource($stream) ? $stream : null;
    }

    /**
     * A URL da linha, se for http(s) no host que o adapter geraria hoje. O host
     * esperado vem das configurações do admin, não da linha.
     */
    protected function trustedUrl(Upload $upload): ?string
    {
        $url = (string) $upload->remote_url;
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $expected = strtolower((string) parse_url($this->generatedUrl($upload), PHP_URL_HOST));

        return $expected !== '' && hash_equals($expected, $host) ? $url : null;
    }

    /**
     * `generateUrl` é protected na base Flysystem do fof/upload, por isso o
     * Closure::bind. Só lê configurações e o path, e só escreve no File dado.
     */
    protected function generatedUrl(Upload $upload): string
    {
        $adapter = $this->adapterOf($upload);
        $file = $this->file($upload);
        $file->url = '';

        $generate = \Closure::bind(function (File $file): void {
            $this->meta = ['path' => $file->path];
            $this->generateUrl($file);
        }, $adapter, Flysystem::class);

        $generate($file);

        return (string) $file->url;
    }

    /**
     * Baixa para um stream temporário, com limite de conexão, de tempo e de tamanho
     * (bem maior que o registrado não é o arquivo). Sem redirects, que sairiam do
     * host checado. Só 200 conta.
     *
     * @return resource|null
     */
    protected function fetch(string $url, Upload $upload)
    {
        $sink = Utils::streamFor(fopen('php://temp/maxmemory:'.(2 * 1024 * 1024), 'w+b'));
        $limit = max((int) $upload->size, 1) * 2 + 1024 * 1024;

        try {
            $response = $this->http()->request('GET', $url, [
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout'         => self::TIMEOUT,
                'allow_redirects' => false,
                'http_errors'     => false,
                'sink'            => $sink,
                'progress'        => function ($expected, $downloaded) use ($limit) {
                    if ($expected > $limit || $downloaded > $limit) {
                        throw new \RuntimeException('Remote file is larger than the upload it should be.');
                    }
                },
            ]);
        } catch (\Throwable $e) {
            $sink->close();

            return null;
        }

        // De novo no fim: nem todo handler chama o callback de progresso.
        if ($response->getStatusCode() !== 200 || ($response->getBody()->getSize() ?? 0) > $limit) {
            $sink->close();

            return null;
        }

        // Wrapper, não detach: mantém vivo o stream PSR, que fecharia o handle ao
        // sair do escopo.
        try {
            $body = $response->getBody();
            $body->rewind();

            return StreamWrapper::getResource($body);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function http(): ClientInterface
    {
        return new Client();
    }

    /**
     * Para arquivo novo: o adapter escolhido no chat ou, sem escolha, o do
     * mapeamento de mimes do fof/upload.
     */
    protected function adapterFor(string $mime): Flysystem
    {
        $key = trim((string) $this->settings->get('ramon-chat.fof_upload_adapter', ''));

        try {
            $adapter = $key !== ''
                ? $this->manager()->instantiate($key)
                : $this->container->make(Util::class)->getAdapterForMime($mime);
        } catch (\Throwable $e) {
            throw new StorageFailed('fof/upload adapter unavailable: '.$e->getMessage(), 0, $e);
        }

        return $this->accept($adapter, $key !== '' ? $key : "mapping for {$mime}");
    }

    /**
     * Para arquivo existente: a chave gravada, não a configuração atual, para a
     * troca de adapter não deixar os antigos órfãos.
     */
    protected function adapterOf(Upload $upload): Flysystem
    {
        $key = (string) $upload->storage_adapter;

        if ($key === '') {
            throw new StorageFailed("Upload {$upload->id} has no recorded fof/upload adapter.");
        }

        try {
            $adapter = $this->manager()->instantiate($key);
        } catch (\Throwable $e) {
            throw new StorageFailed("fof/upload adapter {$key} unavailable: ".$e->getMessage(), 0, $e);
        }

        return $this->accept($adapter, $key);
    }

    protected function accept(mixed $adapter, string $label): Flysystem
    {
        if (! $adapter instanceof Flysystem) {
            throw new StorageFailed("fof/upload adapter ({$label}) is not a Flysystem adapter and cannot hold chat attachments.");
        }

        return $adapter;
    }

    /**
     * Registro em memória que a API do adapter exige. Nunca é salvo.
     */
    protected function file(Upload $upload): File
    {
        $file = new File();
        $file->type = (string) $upload->mime_type;
        $file->size = (int) $upload->size;

        if ($upload->path) {
            $file->path = $upload->path;
            $file->base_name = basename(str_replace('\\', '/', $upload->path));
        }

        if ($upload->remote_url) {
            $file->url = $upload->remote_url;
        }

        return $file;
    }

    protected function manager(): Manager
    {
        return $this->container->make(Manager::class);
    }
}
