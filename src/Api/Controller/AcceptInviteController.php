<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Api\Client as ApiClient;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelInvite;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Service\InvitationManager;
use Ramon\Chat\Service\MembershipManager;
use Throwable;

/**
 * O convidado aceita e entra no canal. Rota própria porque o canal privado não é
 * visível a quem ainda não é membro, e o endpoint `join` nunca acharia o model.
 * A autorização é o convite mais InvitationManager::mayEnter, para o convite não
 * furar a permissão da categoria. Responde com o canal já serializado.
 */
class AcceptInviteController implements RequestHandlerInterface
{
    public function __construct(
        protected Events $events,
        protected MembershipManager $memberships,
        protected InvitationManager $invitations,
        protected ApiClient $api,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $channelId = (int) Arr::get((array) $request->getAttribute('routeParameters'), 'id', 0);

        $invite = ChannelInvite::query()
            ->where('channel_id', $channelId)
            ->where('user_id', $actor->id)
            ->with(['channel', 'inviter'])
            ->first();

        $channel = $invite?->channel;

        if ($invite === null || $channel === null || $channel->isDeleted()) {
            throw new ModelNotFoundException();
        }

        if (! $this->invitations->mayEnter($actor, $channel)) {
            throw new PermissionDeniedException();
        }

        // Outro aceite em paralelo já usou o convite: a entrada é dele, e
        // anunciar de novo gravaria a linha "entrou" duas vezes.
        if ($this->invitations->accept($channel, $actor) === null) {
            return new JsonResponse($this->serialize($request, $channel));
        }

        $this->memberships->join($channel, $actor);

        $this->events->dispatch(new UserJoinedChannel(
            $channel,
            $actor,
            $actor,
            acceptedInvite: true,
            invitedBy: $invite->inviter
        ));

        return new JsonResponse($this->serialize($request, $channel));
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(ServerRequestInterface $request, Channel $channel): array
    {
        try {
            $document = json_decode(
                (string) $this->api
                    ->withoutErrorHandling()
                    ->withParentRequest($request)
                    ->get('/chat-channels/'.$channel->id)
                    ->getBody(),
                true
            );

            if (is_array($document) && isset($document['data']['id'])) {
                return $document;
            }
        } catch (Throwable $e) {
            $this->logger->warning('[ramon-chat] could not serialise an accepted channel', [
                'channel' => (int) $channel->id,
                'class'   => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'data' => [
                'type' => 'chat-channels',
                'id'   => (string) $channel->id,
            ],
        ];
    }
}
