<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Draft;

/**
 * Todos os rascunhos do ator, para o cliente restaurar o composer numa só
 * requisição ao abrir o chat, e não uma por canal.
 */
class ListDraftsController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        // Só canais ainda visíveis: rascunho de canal a que o ator perdeu acesso
        // não volta.
        $visibleChannelIds = Channel::query()
            ->whereVisibleTo($actor)
            ->pluck('id');

        $drafts = Draft::query()
            ->where('user_id', $actor->id)
            ->whereIn('channel_id', $visibleChannelIds)
            ->get();

        return new JsonResponse([
            'data' => $drafts->map(fn (Draft $draft) => [
                'type'       => 'chat-drafts',
                'id'         => (string) $draft->id,
                'attributes' => [
                    'channelId' => $draft->channel_id,
                    'threadId'  => $draft->thread_id,
                    'content'   => $draft->content,
                ],
            ])->values()->all(),
        ]);
    }
}
