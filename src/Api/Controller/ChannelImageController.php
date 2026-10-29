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
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\ChannelWasEdited;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * Define (POST) ou remove (DELETE) a imagem do canal. Não estende o
 * UploadImageController do core, que exige admin: aqui vale a policy `edit`.
 * Reaproveita o validador e o decode/re-encode, para o arquivo gravado ser gerado
 * pelo servidor e não o que o usuário enviou.
 */
class ChannelImageController implements RequestHandlerInterface
{
    protected Filesystem $uploadDir;

    /** Nome do campo multipart e prefixo do arquivo gravado. */
    protected const FIELD = 'image';

    public function __construct(
        protected ImageManager $imageManager,
        protected Container $container,
        protected Events $events,
        Factory $filesystemFactory
    ) {
        $this->uploadDir = $filesystemFactory->disk('flarum-assets');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $id = (int) Arr::get((array) $request->getAttribute('routeParameters'), 'id', 0);

        // whereVisibleTo antes da policy: canal invisível responde igual a canal
        // inexistente.
        $channel = Channel::whereVisibleTo($actor)->find($id);

        if (! $channel instanceof Channel) {
            throw new ForbiddenException();
        }

        if (! $actor->can('edit', $channel)) {
            throw new ForbiddenException();
        }

        $previous = $channel->image_path;

        if ($request->getMethod() === 'DELETE') {
            $channel->image_path = null;
            $channel->save();

            $this->discard($previous);

            // Edição como outra qualquer, para a sidebar dos membros atualizar
            // sem reload.
            $this->events->dispatch(new ChannelWasEdited($channel, $actor));

            return new JsonResponse(['data' => ['imageUrl' => null]]);
        }

        $file = Arr::get($request->getUploadedFiles(), self::FIELD);

        if ($file === null) {
            throw new ValidationException([self::FIELD => 'No file was uploaded.']);
        }

        // Tamanho e mime lidos dos bytes, não do Content-Type do navegador.
        $this->container->make(BotAvatarValidator::class)->assertImageValid(self::FIELD, $file);

        $image = $this->imageManager->read($file->getStream()->getMetadata('uri'))
            ->cover(128, 128);

        $animated = $image->isAnimated();
        $encoded = $animated ? $image->toGif() : $image->toWebp();

        $name = 'ramon-chat-channel-'.$channel->id.'-'
            .Str::lower(Str::random(8)).'.'.($animated ? 'gif' : 'webp');

        $this->uploadDir->put($name, (string) $encoded);

        $channel->image_path = $name;
        $channel->save();

        // Só depois de gravar a nova: uma falha não pode deixar o canal sem
        // nenhuma.
        $this->discard($previous);

        $this->events->dispatch(new ChannelWasEdited($channel, $actor));

        return new JsonResponse(['data' => [
            'id'       => (int) $channel->id,
            'imageUrl' => $channel->imageUrl(),
        ]]);
    }

    protected function discard(?string $path): void
    {
        if ($path && $this->uploadDir->exists($path)) {
            $this->uploadDir->delete($path);
        }
    }
}
