<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Realtime\ChatBroadcaster;

/**
 * Envia `ramonChat.pong` ao canal privado do ator. Prova o caminho PHP → daemon →
 * navegador logo após a inscrição, para o cliente desligar o polling sem esperar a
 * primeira mensagem. Um por usuário a cada 3 s; o excesso responde 204 sem push.
 */
class RealtimePingController implements RequestHandlerInterface
{
    public const THROTTLE_SECONDS = 3;

    public function __construct(
        protected ChatBroadcaster $broadcaster,
        protected Cache $cache
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('useChat');

        $userId = (int) $actor->id;

        if ($this->cache->add('ramon-chat.ping.'.$userId, 1, self::THROTTLE_SECONDS)) {
            $this->broadcaster->toUser($userId, 'ramonChat.pong', ['at' => time()]);
        }

        return new EmptyResponse(204);
    }
}
