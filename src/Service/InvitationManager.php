<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelInvite;

/**
 * Cria e encerra convites para um canal.
 *
 * Quem gerencia convida, e o convidado aceita ou recusa. A membership só nasce
 * no aceite, pelo MembershipManager; aqui existe só o convite.
 */
class InvitationManager
{
    /**
     * Quanto dura uma recusa: nesse intervalo o canal não convida a mesma pessoa
     * de novo, para um "não" não virar fila de notificações. Admins ficam isentos.
     */
    public const DECLINE_COOLDOWN_SECONDS = 86400;

    public function __construct(
        protected ExtensionManager $extensions,
        protected ChannelOwnership $ownership,
        protected Cache $cache
    ) {
    }

    /**
     * Se o convidado poderia entrar no canal, tirando o fato de ser privado. O
     * resto vale: chat habilitado, canal aberto e permissão na categoria
     * restrita. Convite não é porta lateral para uma permissão do fórum.
     */
    public function mayEnter(User $actor, Channel $channel): bool
    {
        if (! $actor->exists || ! $actor->can('useChat')) {
            return false;
        }

        if (! $channel->isOpen()) {
            return false;
        }

        if ($channel->isDirect() || $channel->tag_id === null) {
            return true;
        }

        if (! $this->extensions->isEnabled('flarum-tags')) {
            return false;
        }

        return \Flarum\Tags\Tag::query()
            // @phpstan-ignore method.notFound (flarum/tags model scope)
            ->whereHasPermission($actor, 'viewForum')
            ->whereKey($channel->tag_id)
            ->exists();
    }

    /**
     * Convida quem ainda não é membro, não foi convidado e não recusou há pouco.
     *
     * Membro oculto só conta como membro para quem pode saber dele
     * (ChannelOwnership::seesHiddenMembers); para os outros é convidado normalmente,
     * senão a diferença na resposta o revelaria.
     *
     * Uma consulta de memberships, uma de convites e um insert só: uma requisição
     * pode convidar até cinquenta pessoas.
     * @param  iterable<User>  $users
     * @return ChannelInvite[] os convites criados, na ordem recebida
     */
    public function invite(Channel $channel, iterable $users, User $inviter): array
    {
        $users = is_array($users) ? $users : iterator_to_array($users, false);

        $channel->rememberLookupsFor(array_map(fn (User $user) => (int) $user->id, $users));

        $invitees = [];

        foreach ($users as $user) {
            $membership = $channel->membershipFor($user);

            if ($membership !== null
                && (! $membership->isHidden() || $this->ownership->seesHiddenMembers($inviter))) {
                continue;
            }

            if ($channel->pendingInviteFor($user) !== null) {
                continue;
            }

            if (! $inviter->isAdmin() && $this->cache->has($this->declineKey($channel, $user))) {
                continue;
            }

            $invitees[(int) $user->id] = $user;
        }

        if ($invitees === []) {
            return [];
        }

        $now = Carbon::now();

        ChannelInvite::query()->insert(array_map(fn (User $user) => [
            'channel_id' => $channel->id,
            'user_id'    => $user->id,
            'inviter_id' => $inviter->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($invitees)));

        $invites = ChannelInvite::query()
            ->where('channel_id', $channel->id)
            ->whereIn('user_id', array_keys($invitees))
            ->get()
            ->keyBy('user_id');

        $created = [];

        foreach ($invitees as $id => $user) {
            /** @var ChannelInvite|null $invite */
            $invite = $invites->get($id);

            $channel->forgetInvite($user);

            if ($invite === null) {
                continue;
            }

            $invite->setRelation('channel', $channel);
            $invite->setRelation('user', $user);
            $invite->setRelation('inviter', $inviter);

            $created[] = $invite;
        }

        return $created;
    }

    /**
     * Consome o convite do usuário e o entrega a quem vai criar a membership.
     * Null se não havia convite.
     */
    public function accept(Channel $channel, User $user): ?ChannelInvite
    {
        return $this->remove($channel, $user);
    }

    public function decline(Channel $channel, User $user): ?ChannelInvite
    {
        $invite = $this->remove($channel, $user);

        if ($invite !== null) {
            $this->cache->put($this->declineKey($channel, $user), true, self::DECLINE_COOLDOWN_SECONDS);
        }

        return $invite;
    }

    protected function declineKey(Channel $channel, User $user): string
    {
        return 'ramon-chat.invite-declined.'.$channel->id.'.'.$user->id;
    }

    public function cancel(Channel $channel, User $user): ?ChannelInvite
    {
        return $this->remove($channel, $user);
    }

    /**
     * Os convites pendentes do canal feitos pelo mesmo autor.
     *
     * É o conjunto que o NotificationSyncer precisa: casa por tipo, subject e
     * remetente e recebe a lista completa de quem ainda deve ter a notificação.
     * @return User[]
     */
    public function pendingUsersInvitedBy(Channel $channel, ?User $inviter): array
    {
        return ChannelInvite::query()
            ->where('channel_id', $channel->id)
            ->when(
                $inviter !== null,
                fn ($query) => $query->where('inviter_id', $inviter->id),
                fn ($query) => $query->whereNull('inviter_id')
            )
            ->with('user')
            ->get()
            ->map(fn (ChannelInvite $invite) => $invite->user)
            ->filter()
            ->values()
            ->all();
    }

    protected function remove(Channel $channel, User $user): ?ChannelInvite
    {
        return ChannelInvite::query()->getConnection()->transaction(function () use ($channel, $user) {
            /** @var ChannelInvite|null $invite */
            // Travado: dois aceites em paralelo leriam o mesmo convite e ambos anunciariam
            // a entrada. O segundo espera e não acha nada.
            $invite = ChannelInvite::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->with('inviter')
                ->first();

            if ($invite === null) {
                return null;
            }

            $invite->delete();

            $invite->setRelation('channel', $channel);
            $invite->setRelation('user', $user);

            $channel->forgetInvite($user);

            return $invite;
        });
    }
}
