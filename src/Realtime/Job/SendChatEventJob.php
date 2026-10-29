<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime\Job;

use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;
use Ramon\Chat\Access\PermissionSetVisibility;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;

/**
 * Entrega um evento do chat ao daemon de websocket, resolvendo a audiência. Se
 * vai para a fila ou roda na hora é decisão do ChatBroadcaster (padrão: na hora).
 */
class SendChatEventJob extends AbstractJob
{
    /**
     * Uma tentativa: quando o retry rodasse, os clientes já teriam reconciliado
     * pela API.
     */
    public int $tries = 1;

    /**
     * Membros resolvidos por vez, para um canal do tamanho do fórum custar a
     * memória de um lote só.
     */
    public const AUDIENCE_BATCH = 500;

    /**
     * @param  array<string, mixed>  $payload
     * @param  int|null  $channelId     Distribui aos membros deste canal.
     * @param  int|null  $userId        Ou entrega só a este usuário.
     * @param  int|null  $exceptUserId  O ator, que já sabe o que aconteceu.
     * @param  bool  $moderators        Ou entrega a todos com `ramon-chat.moderate`.
     */
    public function __construct(
        protected string $event,
        protected array $payload,
        protected ?int $channelId = null,
        protected ?int $userId = null,
        protected ?int $exceptUserId = null,
        protected bool $moderators = false
    ) {
        parent::__construct();
    }

    public function __invoke(Container $container, LoggerInterface $log): void
    {
        $pusher = $this->pusher($container);

        if ($pusher === null) {
            return;
        }

        if ($this->userId !== null) {
            $this->send($pusher, $log, $this->singleUser($this->userId));

            return;
        }

        if ($this->moderators) {
            $this->send($pusher, $log, $this->chatModerators());

            return;
        }

        $this->eachMemberBatch(fn (Collection $batch) => $this->send($pusher, $log, $batch));
    }

    /**
     * @param  Collection<int, int>  $recipients  Ids de usuários.
     */
    protected function send(Pusher $pusher, LoggerInterface $log, Collection $recipients): void
    {
        // O Pusher limita o trigger a 100 canais por chamada.
        foreach ($recipients->chunk(100) as $chunk) {
            $channels = $chunk->map(fn (int $id) => 'private-user='.$id)->values()->all();

            try {
                $pusher->trigger($channels, $this->event, $this->payload);
            } catch (\Throwable $e) {
                // Loga sem relançar: a mensagem já foi gravada e o cliente
                // reconcilia pela API.
                $log->warning('[ramon/chat] realtime trigger failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Passa os membros elegíveis a $send, AUDIENCE_BATCH por vez.
     *
     * @param  callable(Collection<int, int>): void  $send
     */
    protected function eachMemberBatch(callable $send): void
    {
        $channel = Channel::query()->find($this->channelId);

        if ($channel === null) {
            return;
        }

        // Não filtra por quem está conectado: a lista de canais do Pusher é
        // eventualmente consistente e perderia mensagens; disparar para canal sem
        // assinante é de graça.
        $memberships = ChannelUser::query()
            ->where('channel_id', $channel->id)
            ->whereNull('left_at')
            ->when($this->exceptUserId !== null, fn ($q) => $q->where('user_id', '!=', $this->exceptUserId))
            ->select(['id', 'user_id']);

        // Uma resposta por conjunto de permissões, compartilhada entre lotes: isto
        // roda na request de quem enviou.
        $visibility = new PermissionSetVisibility();

        // Em canal direto ou sem tag, filiação mais o gate do chat já é a regra. O
        // scope exclui canal apagado, e o aviso de que ele sumiu precisa chegar.
        $gateOnly = $channel->isDirect() || $channel->tag_id === null || $channel->deleted_at !== null;
        $channelId = (int) $channel->id;

        $memberships->chunkById(self::AUDIENCE_BATCH, function ($batch) use ($send, $visibility, $gateOnly, $channelId) {
            // `groups` em eager load porque `permissionGroupIds()` o lê. `whereKey`
            // e não `whereIn`, que pelo mixin volta tipado como o builder base e
            // perde o tipo do model.
            $users = User::query()
                ->whereKey($batch->pluck('user_id')->map(fn ($id) => (int) $id)->all())
                ->with('groups')
                ->get();

            // Todos passam pelo gate do chat: a filiação sobrevive à perda de
            // `ramon-chat.use` ou à suspensão.
            $users = $users->filter(fn (User $user) => $visibility->usesChat($user));

            // Canal com tag herda o `viewForum`, e quem entrou antes da categoria
            // ficar restrita não pode continuar recebendo.
            if (! $gateOnly) {
                $users = $users->filter(fn (User $user) => $visibility->channelVisible($user, $channelId));
            }

            $ids = $users->map(fn (User $user) => (int) $user->id)->values();

            if ($ids->isNotEmpty()) {
                $send($ids);
            }
        });
    }

    /**
     * Push para uma pessoa, ainda atrás do gate: eventos de filiação e convite
     * também são dados do chat.
     *
     * @return Collection<int, int>
     */
    protected function singleUser(int $userId): Collection
    {
        $user = User::query()->whereKey($userId)->with('groups')->first();

        if (! $user instanceof User || ! $user->hasPermission('ramon-chat.use')) {
            return collect();
        }

        return collect([$userId]);
    }

    /**
     * Quem cuida da fila de moderação: os grupos com `ramon-chat.moderate` mais os
     * admins, conferidos usuário a usuário (respeita a suspensão). Se a permissão
     * é de todos os membros, nada é enviado; o contador atualiza no próximo load.
     *
     * @return Collection<int, int>
     */
    protected function chatModerators(): Collection
    {
        $groupIds = Permission::query()
            ->where('permission', 'ramon-chat.moderate')
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (in_array(Group::GUEST_ID, $groupIds, true) || in_array(Group::MEMBER_ID, $groupIds, true)) {
            return collect();
        }

        $groupIds[] = Group::ADMINISTRATOR_ID;

        return User::query()
            ->whereHas('groups', fn ($query) => $query->whereIn('groups.id', $groupIds))
            ->with('groups')
            ->get()
            ->filter(fn (User $user) => (int) $user->id !== $this->exceptUserId && $user->hasPermission('ramon-chat.moderate'))
            ->map(fn (User $user) => (int) $user->id)
            ->values();
    }

    /**
     * Null sem flarum/realtime, para o job rodar sem erro se a extensão foi
     * desligada depois.
     */
    protected function pusher(Container $container): ?Pusher
    {
        if (! class_exists(Pusher::class) || ! $container->bound(Pusher::class)) {
            return null;
        }

        try {
            return $container->make(Pusher::class);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
