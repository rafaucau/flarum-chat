<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Storage\UploadStorage;
use Ramon\Chat\Upload;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * Recurso de leitura para incluir anexos junto das mensagens. A criação é no
 * Api\Controller\UploadController, porque JSON:API não expressa multipart.
 *
 * @extends AbstractDatabaseResource<Upload>
 */
class UploadResource extends AbstractDatabaseResource
{
    public function __construct(
        protected UploadStorage $storage
    ) {
    }

    public function type(): string
    {
        return 'chat-uploads';
    }

    public function model(): string
    {
        return Upload::class;
    }

    /**
     * A regra mora no Access\ScopeUploadVisibility, compartilhada com o
     * controller que serve arquivos privados: visível se a mensagem for; pendente,
     * só para quem enviou.
     */
    public function scope(Builder $query, OriginalContext $context): void
    {
        // @phpstan-ignore method.notFound (Flarum model scope)
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()->authenticated(),

            // Só o próprio anexo pendente pode ser descartado; remover um já preso
            // à mensagem alteraria o histórico. Dois argumentos está certo: o
            // Delete tem model (ver ChannelResource).
            Endpoint\Delete::make()
                ->authenticated()
                ->visible(fn (Upload $upload, Context $context) => $upload->message_id === null
                    && $upload->user_id === $context->getActor()->id),
        ];
    }

    /**
     * Descartar o anexo pendente apaga o arquivo junto da linha: o prune acha
     * órfãos pela linha, e o arquivo continuaria acessível pela URL.
     *
     * @param  Upload  $model
     */
    public function delete(object $model, OriginalContext $context): void
    {
        $this->storage->delete($model);

        $model->delete();
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('fileName'),
            Schema\Str::make('mimeType')->nullable(),
            Schema\Integer::make('size'),
            Schema\Integer::make('width')->nullable(),
            Schema\Integer::make('height')->nullable(),
            Schema\Integer::make('messageId')->nullable(),

            Schema\Str::make('url')
                ->get(fn (Upload $u) => $u->url()),

            Schema\Boolean::make('isImage')
                ->get(fn (Upload $u) => $u->isImage()),

            // Se o arquivo passa pela checagem de permissão em vez de sair direto
            // do servidor web. Só informativo: a URL já aponta para o lugar certo.
            Schema\Boolean::make('isPrivate'),

            Schema\DateTime::make('createdAt'),

            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
        ];
    }
}
