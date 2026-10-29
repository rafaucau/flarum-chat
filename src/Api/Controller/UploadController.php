<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Service\ActionThrottle;
use Ramon\Chat\Service\UploadPrivacy;
use Ramon\Chat\Storage\StorageFailed;
use Ramon\Chat\Storage\UploadStorage;
use Ramon\Chat\Upload;

/**
 * Recebe um anexo do composer. Nasce solto (`message_id` null) e o
 * MessageDispatcher o prende à mensagem depois, para o composer mostrar o anexo
 * antes do envio; o comando de retenção limpa os que nunca foram enviados.
 */
class UploadController implements RequestHandlerInterface
{
    /**
     * Mime permitido → extensão. Allow-list: o que não está aqui é recusado, para
     * um tipo perigoso novo não passar por omissão.
     */
    /** Envios por pessoa a cada 10 minutos. */
    protected const UPLOADS_PER_TEN_MINUTES = 30;

    /** Anexos enviados e ainda não presos a uma mensagem, por pessoa. */
    protected const MAX_PENDING = 20;

    protected const ALLOWED = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'image/avif'      => 'avif',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'text/plain'      => 'txt',
        'text/csv'        => 'csv',
        'audio/mpeg'      => 'mp3',
        'audio/ogg'       => 'ogg',
        'audio/wav'       => 'wav',
        'audio/mp4'       => 'm4a',
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected UploadStorage $storage,
        protected Translator $translator,
        protected LoggerInterface $log,
        protected ActionThrottle $throttle
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('useChat');
        $actor->assertCan('ramon-chat.upload');

        if (! (bool) $this->settings->get('ramon-chat.allow_uploads', true)) {
            throw new ValidationException([
                'file' => $this->translator->trans('ramon-chat.api.uploads_disabled'),
            ]);
        }

        // Sem teto, um membro em loop encheria o disco (e hospedaria arquivos no
        // domínio do fórum por 24 h até a limpeza dos órfãos).
        if (! $actor->isAdmin()) {
            if (! $this->throttle->attempt('upload.'.$actor->id, self::UPLOADS_PER_TEN_MINUTES, 600)) {
                throw new ValidationException([
                    'file' => $this->translator->trans('ramon-chat.api.action_rate_limited'),
                ]);
            }

            $pending = Upload::query()->where('user_id', $actor->id)->whereNull('message_id')->count();

            if ($pending >= self::MAX_PENDING) {
                throw new ValidationException([
                    'file' => $this->translator->trans('ramon-chat.api.uploads_pending_limit', ['max' => self::MAX_PENDING]),
                ]);
            }
        }

        /** @var UploadedFileInterface|null $file */
        $file = $request->getUploadedFiles()['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException([
                'file' => $this->translator->trans('ramon-chat.api.upload_missing'),
            ]);
        }

        // O composer diz o destino, para arquivo de canal privado nunca tocar o
        // disco público. É dica, não trava: o dispatcher confere de novo no envio.
        // Canal invisível ao ator responde "não encontrado".
        $channelId = (int) Arr::get($request->getParsedBody(), 'channelId', 0);
        $private = UploadPrivacy::forumClosed();

        if ($channelId > 0) {
            /** @var Channel|null $channel */
            $channel = Channel::whereVisibleTo($actor)->find($channelId);

            if ($channel === null) {
                throw new ValidationException([
                    'channelId' => $this->translator->trans('ramon-chat.api.channel_not_found'),
                ]);
            }

            $private = UploadPrivacy::requiredFor($channel);
        }

        $maxSize = (int) $this->settings->get('ramon-chat.max_upload_size', 10485760);

        if ($maxSize > 0 && (int) $file->getSize() > $maxSize) {
            throw new ValidationException([
                'file' => $this->translator->trans('ramon-chat.api.upload_too_large'),
            ]);
        }

        // Mime real lido do arquivo temporário: o Content-Type vem do cliente e
        // nunca decide o armazenamento.
        $tmp = $file->getStream()->getMetadata('uri');
        $mime = is_string($tmp) ? (mime_content_type($tmp) ?: '') : '';

        if (! isset(self::ALLOWED[$mime])) {
            throw new ValidationException([
                'file' => $this->translator->trans('ramon-chat.api.upload_unsupported', ['type' => $mime]),
            ]);
        }

        $extension = self::ALLOWED[$mime];

        $width = null;
        $height = null;

        // Lido antes de o store consumir o stream.
        if (str_starts_with($mime, 'image/') && is_string($tmp)) {
            $size = @getimagesize($tmp);

            if ($size !== false) {
                [$width, $height] = $size;
            }
        }

        $upload = new Upload();
        $upload->user_id = $actor->id;
        $upload->is_private = $private;
        $upload->mime_type = $mime;
        $upload->size = (int) $file->getSize();
        $upload->file_name = $this->safeFileName($file->getClientFilename() ?? 'file.'.$extension);
        $upload->width = $width;
        $upload->height = $height;

        // O store define o path: privado vai para o disco privado do chat,
        // público para o fof/upload se o admin escolheu. O basename é aleatório;
        // o nome do cliente é só rótulo, nunca parte do path.
        $this->put($upload, $file, $extension);

        try {
            $upload->save();
        } catch (\Throwable $e) {
            // Sem linha, nada acharia esses bytes de novo: nem prune nem delete.
            $this->storage->delete($upload);

            throw $e;
        }

        return new JsonResponse([
            'data' => [
                'type'       => 'chat-uploads',
                'id'         => (string) $upload->id,
                'attributes' => [
                    'fileName' => $upload->file_name,
                    'mimeType' => $upload->mime_type,
                    'size'     => $upload->size,
                    'width'    => $upload->width,
                    'height'   => $upload->height,
                    'url'       => $upload->url(),
                    'isImage'   => $upload->isImage(),
                    'isPrivate' => $upload->is_private,
                ],
            ],
        ], 201);
    }

    /**
     * Grava pelo store escolhido ou recusa. Sem fallback para o disco local se o
     * fof/upload falhar: encher o webroot esconderia a pane até lotar o disco. O
     * membro vê "não foi possível guardar"; o motivo (pode citar o bucket) vai
     * para o log.
     */
    protected function put(Upload $upload, UploadedFileInterface $file, string $extension): void
    {
        $stream = $file->getStream();
        $stream->rewind();
        $resource = StreamWrapper::getResource($stream);

        try {
            $this->storage->forNew((bool) $upload->is_private)->put($upload, $resource, $extension);
        } catch (StorageFailed $e) {
            $this->log->error('[ramon-chat] could not store an upload', [
                'user'  => $upload->user_id,
                'error' => $e->getMessage(),
            ]);

            throw new ValidationException([
                'file' => $this->translator->trans('ramon-chat.api.upload_storage_failed'),
            ]);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * Tira diretórios e caracteres de controle do nome exibido. Volta para os
     * clientes, então também não pode levar markup.
     */
    protected function safeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F<>"\']/u', '', $name) ?? 'file';

        return mb_substr(trim($name) ?: 'file', 0, 200);
    }
}
