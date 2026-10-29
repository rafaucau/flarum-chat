<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\Guest;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Storage\Job\PrivatizeRemoteUploads;
use Ramon\Chat\Storage\UploadStorage;
use Ramon\Chat\Storage\UploadStore;
use Ramon\Chat\Upload;

/**
 * Decide em que disco um anexo fica e o move para lá.
 *
 * `chat` fica em `public/assets/chat`, servido direto pelo servidor web.
 * `chat-private` fica em `storage`, fora do webroot, e só sai pelo
 * ServeUploadController, que checa a visibilidade do upload. Anexo de canal
 * privado ou DM precisa estar no segundo, senão qualquer um com a URL o baixa.
 *
 * Com o fof/upload, arquivos públicos ficam no bucket (ver Storage\UploadStorage);
 * privados nunca, então privatizar também traz os bytes de volta.
 *
 * Privacidade é de mão única: canal que volta a ser público mantém os anexos
 * no disco privado, para não republicar o que foi postado sob outra promessa.
 */
class UploadPrivacy
{
    public const PUBLIC_DISK = 'chat';
    public const PRIVATE_DISK = 'chat-private';

    /**
     * O flarum/tags tem dois níveis. O limite é para hierarquia corrompida em
     * loop, tratada como restrita.
     */
    protected const MAX_TAG_DEPTH = 8;

    protected bool $deferring = false;

    /** @var list<array{0: Upload, 1: UploadStore, 2: string}> */
    protected array $deferred = [];

    public function __construct(
        protected Factory $filesystem,
        protected LoggerInterface $log,
        protected UploadStorage $storage,
        protected Queue $queue
    ) {
    }

    /**
     * Se os anexos deste canal precisam ficar no disco privado: DM, canal privado,
     * canal apagado, canal vinculado a tag restrita, ou qualquer canal quando o
     * fórum é fechado a visitantes (o disco público serve sem login).
     */
    /** Visitantes não leem o fórum: nada do chat pode sair do disco público. */
    public static function forumClosed(): bool
    {
        return ! (new Guest())->hasPermission('viewForum');
    }

    public static function requiredFor(Channel $channel): bool
    {
        if ($channel->isDirect() || $channel->isPrivate() || $channel->isDeleted() || self::forumClosed()) {
            return true;
        }

        if ($channel->tag_id === null) {
            return false;
        }

        // Null sem o flarum/tags; aí um tag id na linha é resto e não restringe nada.
        $relation = $channel->tag();

        if ($relation === null) {
            return false;
        }

        // Relação carregada antes de trocar a tag responderia pela antiga.
        $loaded = $channel->relationLoaded('tag') ? $channel->getRelation('tag') : null;
        $tag = $loaded !== null && (int) $loaded->getKey() === (int) $channel->tag_id
            ? $loaded
            : $relation->first();

        return self::tagIsRestricted($tag);
    }

    /**
     * Se a tag, ou alguma acima dela, é restrita.
     *
     * O flarum/tags esconde filha de mãe invisível, então a filha não é mais
     * pública que a mãe. Tag ausente (apagada) conta como restrita: o canal só
     * aparece para admins, e os arquivos não podem ficar legíveis pela URL.
     */
    public static function tagIsRestricted(?AbstractModel $tag): bool
    {
        for ($depth = 0; $depth < self::MAX_TAG_DEPTH; $depth++) {
            if ($tag === null) {
                return true;
            }

            if ($tag->getAttribute('is_restricted')) {
                return true;
            }

            if ($tag->getAttribute('parent_id') === null) {
                return false;
            }

            $tag = $tag->newQuery()->whereKey((int) $tag->getAttribute('parent_id'))->first();
        }

        return true;
    }

    public static function diskFor(bool $private): string
    {
        return $private ? self::PRIVATE_DISK : self::PUBLIC_DISK;
    }

    /**
     * Move o arquivo para fora do disco público e marca a linha, nessa ordem: se a
     * movimentação falhar, a URL dá 404 (imagem quebrada, não vazamento).
     *
     * No fof/upload é o contrário: a URL pública está no bucket, então a linha muda
     * antes e o Upload::url() passa a dar a rota do controller; depois os bytes vêm
     * para o disco privado e a cópia remota é apagada. Linha privada ainda remota é
     * movimentação inacabada, concluída aqui.
     *
     * @throws \RuntimeException quando o arquivo não pode ser movido
     */
    public function privatize(Upload $upload): void
    {
        if ($upload->isRemote()) {
            if (! $upload->is_private) {
                $upload->is_private = true;
                $upload->save();
            }

            $this->pullRemote($upload);

            return;
        }

        if ($upload->is_private) {
            return;
        }

        $this->move($upload->path);

        $upload->is_private = true;
        $upload->save();
    }

    /**
     * Os anexos ainda públicos das mensagens dadas.
     *
     * @param  int[]  $messageIds
     * @return int quantos foram movidos
     */
    public function privatizeMessages(array $messageIds): int
    {
        $messageIds = array_values(array_filter(array_map('intval', $messageIds)));

        if ($messageIds === []) {
            return 0;
        }

        return $this->privatizeEach(
            Upload::query()
                ->where('is_private', false)
                ->whereHas('message', fn ($query) => $query->whereKey($messageIds))
                ->get()
        );
    }

    /**
     * As linhas dadas, de qualquer canal, para quem as achou por outro caminho
     * que não mensagem ou canal.
     *
     * @param  iterable<Upload>  $uploads
     * @return int quantos foram movidos
     */
    public function privatizeUploads(iterable $uploads): int
    {
        return $this->privatizeEach($uploads);
    }

    /**
     * Todos os anexos públicos de um canal que ficou privado. Em chunks: um canal
     * movimentado pode ter milhares.
     *
     * @return int quantos foram movidos
     */
    public function privatizeChannel(Channel $channel): int
    {
        $moved = 0;

        // Arquivos do fof/upload são marcados aqui, para a URL do bucket não sair
        // de novo; os bytes vêm num job, pois cada um é download e upload. Marcados
        // antes para a passada local abaixo não os pegar.
        $remote = Upload::query()
            ->where('storage', Upload::STORAGE_FOF)
            ->where('is_private', false)
            ->whereHas('message', fn ($query) => $query->where('channel_id', $channel->id))
            ->update(['is_private' => true, 'updated_at' => Carbon::now()]);

        if ($remote > 0) {
            $this->queueRemote((int) $channel->id);
        }

        Upload::query()
            ->where('is_private', false)
            ->whereHas('message', fn ($query) => $query->where('channel_id', $channel->id))
            ->chunkById(200, function (iterable $uploads) use (&$moved) {
                $moved += $this->privatizeEach($uploads);
            });

        return $moved;
    }

    /**
     * A metade do job de privatizeChannel(): arquivos marcados privados ainda no
     * fof/upload. Pode rodar de novo; o que já voltou é local e não casa.
     *
     * @return array{0: int, 1: int} quantos foram movidos e quantos encontrados
     */
    public function pullRemoteInChannel(int $channelId): array
    {
        $moved = 0;
        $found = 0;

        Upload::query()
            ->where('storage', Upload::STORAGE_FOF)
            ->where('is_private', true)
            ->whereHas('message', fn ($query) => $query->where('channel_id', $channelId))
            ->chunkById(100, function (Collection $uploads) use (&$moved, &$found) {
                $found += $uploads->count();
                $moved += $this->privatizeEach($uploads);
            });

        return [$moved, $found];
    }

    /**
     * Enfileirado, não executado: na fila sync padrão dá no mesmo, e numa fila de
     * verdade tira os downloads da requisição. Falha ao enfileirar só é logada;
     * as linhas já estão marcadas, e editar o canal não pode falhar por isso.
     */
    protected function queueRemote(int $channelId): void
    {
        try {
            $this->queue->push(new PrivatizeRemoteUploads($channelId));
        } catch (\Throwable $e) {
            $this->log->error('[ramon-chat] could not move a channel\'s remote uploads to the private disk', [
                'channel' => $channelId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Copia o arquivo remoto para o disco privado num caminho novo, apaga a cópia
     * remota e aponta a linha para a local.
     *
     * Se a remota não sair, a local é removida e a linha fica como estava, para a
     * próxima tentativa recomeçar do mesmo ponto. Gravar mesmo assim deixaria uma
     * cópia pública no bucket sem dono.
     */
    protected function pullRemote(Upload $upload): void
    {
        $remote = $this->storage->for($upload);
        $stream = $remote->readStream($upload);

        if (! is_resource($stream)) {
            throw new \RuntimeException("Could not read upload {$upload->id} from fof/upload.");
        }

        $extension = strtolower(pathinfo(str_replace('\\', '/', $upload->path), PATHINFO_EXTENSION));
        $path = sprintf('%s/%s.%s', Carbon::now()->format('Y/m'), Str::random(28), $extension ?: 'bin');
        $to = $this->filesystem->disk(self::PRIVATE_DISK);

        try {
            $written = $to->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        if (! $written) {
            throw new \RuntimeException("Could not write {$path} to the private chat disk.");
        }

        if ($this->deferring) {
            // O envio ainda pode reverter: o bucket só é apagado depois do commit.
            $this->deferred[] = [clone $upload, $remote, $path];
        } else {
            try {
                $remote->delete($upload);
            } catch (\Throwable $e) {
                $to->delete($path);

                throw $e;
            }
        }

        $upload->path = $path;
        $upload->storage = Upload::STORAGE_LOCAL;
        $upload->storage_adapter = null;
        $upload->remote_url = null;
        $upload->save();
    }

    /**
     * Melhor esforço: um arquivo que não move não pode deixar o resto publicado. A
     * falha é logada como error, pois arquivo público em canal privado é o que o
     * operador precisa saber.
     *
     * @param  iterable<Upload>  $uploads
     */
    protected function privatizeEach(iterable $uploads): int
    {
        $moved = 0;

        foreach ($uploads as $upload) {
            try {
                $this->privatize($upload);
                $moved++;
            } catch (\Throwable $e) {
                $this->log->error('[ramon-chat] could not move an upload to the private disk', [
                    'upload' => $upload->id,
                    'path'   => $upload->path,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return $moved;
    }

    protected function move(string $path): void
    {
        $from = $this->filesystem->disk(self::PUBLIC_DISK);
        $to = $this->filesystem->disk(self::PRIVATE_DISK);

        if (! $from->exists($path)) {
            // Já movido ou nunca gravado: nada público a tirar, e a linha pode ainda estar marcada.
            if (! $to->exists($path)) {
                $this->log->warning('[ramon-chat] upload file missing from both disks', ['path' => $path]);
            }

            return;
        }

        $stream = $from->readStream($path);

        if (! is_resource($stream)) {
            throw new \RuntimeException("Could not read {$path} from the public chat disk.");
        }

        // O Flysystem lê do handle e deixa fechá-lo para quem chamou.
        try {
            $written = $to->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        if (! $written) {
            throw new \RuntimeException("Could not write {$path} to the private chat disk.");
        }

        $from->delete($path);
    }

    /**
     * Roda `$write` (a transação do envio) adiando o apagamento das cópias no
     * fof/upload para depois dela. Apagadas lá dentro, uma reversão deixava a
     * linha apontando para um arquivo que não existe mais e a cópia local sem
     * dono; agora a reversão só apaga a cópia local.
     *
     * @template T
     * @param  callable(): T  $write
     * @return T
     */
    public function deferringRemoteDeletes(callable $write): mixed
    {
        $this->deferring = true;

        try {
            $result = $write();
        } catch (\Throwable $e) {
            foreach ($this->takeDeferred() as [, , $localPath]) {
                $this->filesystem->disk(self::PRIVATE_DISK)->delete($localPath);
            }

            throw $e;
        }

        foreach ($this->takeDeferred() as [$remoteCopy, $remote]) {
            try {
                $remote->delete($remoteCopy);
            } catch (\Throwable $e) {
                // Sobra uma cópia pública sem linha apontando para ela.
                $this->log->error('[ramon-chat] could not delete a remote upload moved to the private disk', [
                    'upload' => (int) $remoteCopy->id,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /** @return list<array{0: Upload, 1: UploadStore, 2: string}> */
    protected function takeDeferred(): array
    {
        $deferred = $this->deferred;
        $this->deferred = [];
        $this->deferring = false;

        return $deferred;
    }
}
