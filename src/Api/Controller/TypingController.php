<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Realtime\PresenceBroadcaster;
use Ramon\Chat\Service\ActionThrottle;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * Avisa que o ator está digitando. Fire-and-forget: responde 204 mesmo sem
 * broadcast, porque o indicador nunca pode atrapalhar o envio de mensagens.
 */
class TypingController implements RequestHandlerInterface
{
    public function __construct(
        protected PresenceBroadcaster $presence,
        protected ActionThrottle $throttle
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body = $request->getParsedBody();
        $channelId = (int) Arr::get($body, 'data.attributes.channelId');
        $typing = (bool) Arr::get($body, 'data.attributes.typing', true);

        // Um sinal por estado por canal a cada 2 s, antes de buscar o canal: um
        // flood custa um hit de cache, não query de visibilidade e push. Start e
        // stop contam separados para o stop nunca ser o descartado.
        if (! $this->throttle->attempt('typing.'.$actor->id.'.'.$channelId.'.'.(int) $typing, 1, 2)) {
            return new EmptyResponse(204);
        }

        /** @var Channel|null $channel */
        $channel = Channel::query()->whereVisibleTo($actor)->find($channelId);

        if ($channel === null) {
            throw new ForbiddenException();
        }

        if (! $actor->can('postMessage', $channel)) {
            throw new ForbiddenException();
        }

        // Quem inspeciona o canal sem aparecer continua sem aparecer: "X está
        // digitando" revelaria a presença que a lista de membros esconde.
        if ($channel->membershipFor($actor)?->isHidden()) {
            return new EmptyResponse(204);
        }

        $this->presence->typing(
            $channel,
            $actor,
            $typing
        );

        return new EmptyResponse(204);
    }
}
