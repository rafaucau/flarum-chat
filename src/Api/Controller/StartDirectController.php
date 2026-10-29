<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Api\Client as ApiClient;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Service\ActionThrottle;
use Ramon\Chat\Service\MembershipManager;
use Throwable;

/**
 * Acha ou cria a conversa direta de um conjunto de participantes. Reabrir com
 * as mesmas pessoas cai na conversa existente, desde que ninguém tenha saído
 * (ver findExisting()).
 */
class StartDirectController implements RequestHandlerInterface
{
    protected const MAX_PARTICIPANTS = Channel::MAX_DIRECT_PARTICIPANTS;

    /** Conversas novas que alguém que não é admin abre por hora. */
    protected const NEW_PER_HOUR = 20;

    public function __construct(
        protected Events $events,
        protected Translator $translator,
        protected MembershipManager $memberships,
        protected ApiClient $api,
        protected LoggerInterface $logger,
        protected ActionThrottle $throttle
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('startDirect');

        $body = $request->getParsedBody();
        $requested = (array) Arr::get($body, 'data.attributes.userIds', []);

        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $requested),
            fn (int $id) => $id > 0 && $id !== (int) $actor->id
        )));

        if ($userIds === []) {
            throw new ValidationException([
                'userIds' => $this->translator->trans('ramon-chat.api.direct_needs_participant'),
            ]);
        }

        if (count($userIds) + 1 > self::MAX_PARTICIPANTS) {
            throw new ValidationException([
                'userIds' => $this->translator->trans('ramon-chat.api.direct_too_many', [
                    'max' => self::MAX_PARTICIPANTS,
                ]),
            ]);
        }

        // Só usuários visíveis ao ator: um id escondido por regra de outra
        // extensão responde igual a um inexistente.
        $users = User::query()->whereVisibleTo($actor)->whereIn('id', $userIds)->get();

        if ($users->count() !== count($userIds)) {
            throw new ValidationException([
                'userIds' => $this->translator->trans('ramon-chat.api.direct_unknown_user'),
            ]);
        }

        // O conjunto completo de participantes, com o ator, identifica o canal.
        $participantIds = $userIds;
        $participantIds[] = (int) $actor->id;
        sort($participantIds);

        // Busca e criação na mesma transação, com as linhas dos participantes
        // travadas em ordem de id: dois pedidos ao mesmo grupo ao mesmo tempo
        // não criam duas conversas, e a ordem fixa evita deadlock.
        [$channel, $created] = Channel::query()->getConnection()->transaction(function () use ($actor, $users, $participantIds) {
            User::query()->whereIn('id', $participantIds)->orderBy('id')->lockForUpdate()->pluck('id');

            $existing = $this->findExisting($participantIds);

            if ($existing !== null) {
                // Re-join é idempotente.
                $this->memberships->join($existing, $actor);

                return [$existing, false];
            }

            // Só conta conversa nova: reabrir não cria nada na lista de ninguém.
            // Sem o limite, variar os participantes encheria a sidebar alheia.
            if (! $actor->isAdmin() && ! $this->throttle->attempt('direct.'.$actor->id, self::NEW_PER_HOUR, 3600)) {
                throw new ValidationException([
                    'userIds' => $this->translator->trans('ramon-chat.api.action_rate_limited'),
                ]);
            }

            $channel = Channel::build(Channel::TYPE_DIRECT, creator: $actor);
            $channel->save();

            $this->memberships->join($channel, $actor);

            foreach ($users as $user) {
                $this->memberships->join($channel, $user);
            }

            $channel->refreshMetadata()->save();

            return [$channel, true];
        });

        if ($created) {
            $this->events->dispatch(new ChannelWasCreated($channel, $actor));
        }

        return new JsonResponse(
            $this->serialize($request, $channel, $created),
            $created ? 201 : 200
        );
    }

    /**
     * O canal como o ChannelResource serviria, poupando um GET ao cliente.
     *
     * `meta.created` diz ao cliente que a conversa é nova: sem mensagens nem
     * fixados, ele marca ambos como carregados e vazios.
     *
     * `meta.serialized` é false se a leitura interna falhou. O canal já foi
     * gravado, então não é erro: o stub basta para o cliente buscá-lo.
     *
     * @return array<string, mixed>
     */
    protected function serialize(ServerRequestInterface $request, Channel $channel, bool $created): array
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
                $document['meta'] = ['created' => $created, 'serialized' => true];

                return $document;
            }
        } catch (Throwable $e) {
            $this->logger->warning('[ramon-chat] could not serialise a direct channel', [
                'channel' => (int) $channel->id,
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'data' => [
                'type' => 'chat-channels',
                'id'   => (string) $channel->id,
            ],
            'meta' => ['created' => $created, 'serialized' => false],
        ];
    }

    /**
     * Casa quando o conjunto de participantes *ativos* é exatamente o pedido.
     * `left_at IS NULL` faz parte do critério de propósito: sair é definitivo, e
     * uma conversa nova abre outro canal em vez de devolver o histórico deixado.
     *
     * @param  int[]  $participantIds  Ordenados.
     */
    protected function findExisting(array $participantIds): ?Channel
    {
        $count = count($participantIds);

        $candidateIds = ChannelUser::query()
            ->select('channel_id')
            ->whereIn('user_id', $participantIds)
            ->whereNull('left_at')
            ->groupBy('channel_id')
            // Todos os pedidos ainda presentes…
            ->havingRaw('COUNT(DISTINCT user_id) = ?', [$count])
            ->pluck('channel_id');

        if ($candidateIds->isEmpty()) {
            return null;
        }

        return Channel::query()
            ->whereIn('id', $candidateIds)
            ->where('type', Channel::TYPE_DIRECT)
            ->whereNull('deleted_at')
            // …e mais ninguém ativo, o que exclui canal com alguém que saiu e
            // superconjuntos. Pelo query builder para a grammar aplicar prefixo
            // de tabela e quoting.
            ->where(
                fn ($sub) => $sub->from('chat_channel_user')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('chat_channel_user.channel_id', 'chat_channels.id')
                    ->whereNull('chat_channel_user.left_at'),
                '=',
                $count
            )
            ->first();
    }
}
