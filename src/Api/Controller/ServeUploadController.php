<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\Http\RequestUtil;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Storage\StorageFailed;
use Ramon\Chat\Storage\UploadStorage;
use Ramon\Chat\Upload;

/**
 * Entrega anexos fora do webroot, único caminho para arquivos de canal privado.
 * Aplica o mesmo scope de visibilidade do recurso JSON:API antes de ler um byte;
 * quem não vê a mensagem recebe 404, não 403, para a URL não confirmar o arquivo.
 *
 * Headers vêm da linha, nunca da requisição: o mime foi detectado nos bytes no
 * upload. Aceita um único Range, porque o Safari não toca áudio/vídeo sem isso.
 */
class ServeUploadController implements RequestHandlerInterface
{
    /**
     * Tipos que o navegador pode exibir inline. O resto vai como download, para
     * um arquivo de texto não ser interpretado como página.
     */
    protected const INLINE = ['image/', 'audio/', 'video/', 'application/pdf'];

    /**
     * Uma hora no cache privado do navegador: quem sai do canal perde o acesso
     * aos arquivos em até uma hora.
     */
    protected const MAX_AGE = 3600;

    public function __construct(
        protected UploadStorage $storage
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $id = (int) Arr::get((array) $request->getAttribute('routeParameters'), 'id', 0);

        /** @var Upload|null $upload */
        $upload = $id > 0 ? Upload::whereVisibleTo($actor)->find($id) : null;

        if ($upload === null) {
            throw new RouteNotFoundException();
        }

        // Arquivo público no fof/upload tem endereço próprio; servir por aqui
        // gastaria a banda que o bucket deveria carregar.
        if ($upload->isRemote() && ! $upload->is_private) {
            throw new RouteNotFoundException();
        }

        try {
            $store = $this->storage->for($upload);
        } catch (StorageFailed $e) {
            throw new RouteNotFoundException();
        }

        // Null para arquivo no fof/upload. Só passa por aqui durante a migração
        // para o disco privado (ver Upload) e vai inteiro, sem Range.
        $size = $store->size($upload);

        if ($size === null && ! $upload->isRemote()) {
            throw new RouteNotFoundException();
        }

        $etag = '"'.hash('sha256', $upload->id.':'.($size ?? $upload->size).':'.$upload->updated_at->getTimestamp()).'"';

        $headers = [
            'Content-Type'           => $upload->mime_type ?: 'application/octet-stream',
            'Content-Disposition'    => $this->disposition($upload),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age='.self::MAX_AGE,
            'ETag'                   => $etag,
        ];

        if ($size !== null) {
            $headers['Accept-Ranges'] = 'bytes';
        }

        // PDF pode ter script; em sandbox não alcança origem, cookies nem DOM do
        // fórum, mesmo aberto inline.
        if ($upload->mime_type === 'application/pdf') {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return new EmptyResponse(304, Arr::only($headers, ['ETag', 'Cache-Control']));
        }

        $stream = $store->readStream($upload);

        if (! is_resource($stream)) {
            throw new RouteNotFoundException();
        }

        $body = Utils::streamFor($stream);

        if ($size === null) {
            return new Response($body, 200, $headers);
        }

        $range = $this->range($request->getHeaderLine('Range'), $size);

        if ($range === false) {
            $body->close();

            return new EmptyResponse(416, ['Content-Range' => 'bytes */'.$size]);
        }

        if ($range !== null) {
            [$start, $end] = $range;

            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
            $headers['Content-Length'] = (string) ($end - $start + 1);

            return new Response(new LimitStream($body, $end - $start + 1, $start), 206, $headers);
        }

        $headers['Content-Length'] = (string) $size;

        return new Response($body, 200, $headers);
    }

    /**
     * O header `Range` como [início, fim]; null se ausente ou não suportado (só
     * um intervalo de bytes), false se impossível de atender.
     *
     * @return array{0: int, 1: int}|null|false
     */
    protected function range(string $header, int $size): array|null|false
    {
        if ($header === '' || $size === 0 || ! preg_match('/^bytes=(\d*)-(\d*)$/', $header, $m)) {
            return null;
        }

        if ($m[1] === '' && $m[2] === '') {
            return null;
        }

        if ($m[1] === '') {
            // Range de sufixo (últimos N bytes). Zero é 416 pela RFC 9110, não
            // um 206 vazio.
            $length = min((int) $m[2], $size);

            if ($length === 0) {
                return false;
            }

            return [$size - $length, $size - 1];
        }

        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);

        if ($start >= $size || $start > $end) {
            return false;
        }

        return [$start, $end];
    }

    protected function disposition(Upload $upload): string
    {
        $type = (string) $upload->mime_type;
        $inline = false;

        foreach (self::INLINE as $prefix) {
            if (str_starts_with($type, $prefix)) {
                $inline = true;
                break;
            }
        }

        // O nome já vem sem path e caracteres de controle; aqui fica só o que
        // cabe numa quoted-string, com o nome completo na forma estendida
        // (RFC 5987) ao lado.
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $upload->file_name) ?: 'file';

        return sprintf(
            "%s; filename=\"%s\"; filename*=UTF-8''%s",
            $inline ? 'inline' : 'attachment',
            trim($ascii, '_') ?: 'file',
            rawurlencode($upload->file_name)
        );
    }
}
