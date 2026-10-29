<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Api\Controller\UploadImageController;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Upload do avatar do bot, sobre o UploadImageController do core. O motivo
 * principal é o `makeImage`: a imagem é decodificada e re-encodada, então um PNG
 * com PHP anexado sai daqui como webp limpo.
 *
 * @see DeleteBotAvatarController
 */
class UploadBotAvatarController extends UploadImageController
{
    protected string $filePathSettingKey = 'ramon-chat.bot_avatar_path';
    protected string $filenamePrefix = 'ramon-chat-bot';
    protected ?string $validator = BotAvatarValidator::class;

    private string $resolvedExtension = 'webp';

    protected function makeImage(UploadedFileInterface $file): EncodedImageInterface
    {
        // Quadrado e pequeno: aparece a 34px no stream, e o original custaria
        // banda em cada mensagem do bot.
        $image = $this->imageManager->read($file->getStream()->getMetadata('uri'))
            ->cover(200, 200);

        // Avatar animado continua animado: virar webp congelaria o GIF.
        if ($image->isAnimated()) {
            $this->resolvedExtension = 'gif';

            return $image->toGif();
        }

        $this->resolvedExtension = 'webp';

        return $image->toWebp();
    }

    protected function fileExtension(ServerRequestInterface $request, UploadedFileInterface $file): string
    {
        return $this->resolvedExtension;
    }
}
