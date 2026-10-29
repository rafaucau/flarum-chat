<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\User\User;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;

/**
 * Cria, atualiza e encerra memberships, mantendo o `user_count`
 * desnormalizado do canal em dia.
 */
class MembershipManager
{
    public function __construct(
        protected OwnershipSuccession $succession
    ) {
    }

    /**
     * Idempotente: entrar de novo reativa a saída anterior em vez de duplicar a
     * linha, o que faz uma DM reiniciada reaver as mensagens antigas.
     */
    /**
     * @param  bool  $hidden  entrar sem aparecer: fora da lista de membros e do
     *                        `user_count`, sem anúncio. Para moderadores lerem o
     *                        canal sem mudar a conversa.
     *
     * Se algo mudou está na linha retornada (`wasRecentlyCreated` ou
     * `wasChanged(['left_at', 'hidden'])`); quem anuncia lê isso, para repetir a
     * requisição não repetir o anúncio.
     */
    public function join(
        Channel $channel,
        User $user,
        ?int $notificationLevel = null,
        bool $hidden = false
    ): ChannelUser {
        return ChannelUser::query()->getConnection()->transaction(function () use ($channel, $user, $notificationLevel, $hidden) {
            /** @var ChannelUser|null $membership */
            $membership = ChannelUser::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $user->id)
                ->first();

            $isNew = $membership === null;
            $wasGone = $membership !== null && $membership->left_at !== null;
            $wasHidden = $membership !== null && $membership->isHidden();

            if ($isNew) {
                $membership = new ChannelUser();
                $membership->channel_id = $channel->id;
                $membership->user_id = $user->id;
                $membership->notification_level = $notificationLevel ?? ChannelUser::LEVEL_MENTIONS;
                $membership->joined_at = Carbon::now();
            }

            $membership->following = true;
            $membership->left_at = null;
            $membership->setAttribute('hidden', $hidden);

            if ($notificationLevel !== null) {
                $membership->notification_level = $notificationLevel;
            }

            // Quem volta começa em dia com o canal, sem badge do que perdeu.
            if ($wasGone) {
                $membership->markReadUpTo($channel->last_message_id);
            }

            $membership->save();

            // `user_count` é o que os outros veem, então conta só membros visíveis. Cada
            // ramo abaixo é uma transição de visibilidade, não de membership.
            $wasVisible = ! $isNew && ! $wasGone && ! $wasHidden;
            $isVisible = ! $hidden;

            if (! $wasVisible && $isVisible) {
                $channel->increment('user_count');
            } elseif ($wasVisible && ! $isVisible) {
                if ($channel->user_count > 0) {
                    $channel->decrement('user_count');
                }
            }

            // A instância memoiza as consultas e o endpoint a serializa logo depois; um
            // miss velho diria ao membro que ele não é membro.
            $channel->forgetMembership($user);

            return $membership;
        });
    }

    /**
     * Sai sem apagar a linha, para leitura e histórico sobreviverem a uma volta.
     *
     * Dono que sai passa o canal ao moderador mais antigo na mesma transação
     * (OwnershipSuccession); o anúncio sai após o commit, antes do da saída.
     */
    public function leave(Channel $channel, User $user, ?User $actor = null): ?ChannelUser
    {
        $settled = null;

        $membership = ChannelUser::query()->getConnection()->transaction(function () use ($channel, $user, &$settled) {
            /** @var ChannelUser|null $membership */
            $membership = ChannelUser::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->first();

            if ($membership === null) {
                $this->optOut($channel, $user);

                return null;
            }

            $wasHidden = $membership->isHidden();

            $membership->following = false;
            $membership->left_at = Carbon::now();
            $membership->unread_count = 0;
            $membership->unread_mentions_count = 0;
            $membership->save();

            // Membro oculto nunca foi contado, então a saída não decrementa.
            if (! $wasHidden && $channel->user_count > 0) {
                $channel->decrement('user_count');
            }

            $settled = $this->succession->settle($channel, $user);

            $channel->forgetMembership($user);

            return $membership;
        });

        $this->succession->announce($settled, $actor);

        return $membership;
    }

    public function updatePreferences(
        Channel $channel,
        User $user,
        ?int $notificationLevel = null,
        ?bool $muted = null
    ): ?ChannelUser {
        /** @var ChannelUser|null $membership */
        $membership = ChannelUser::query()
            ->where('channel_id', $channel->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        // Só com membership ativa: senão escolher o nível de notificação seria porta
        // lateral para entrar em canal fechado ou privado.
        if ($membership === null) {
            return null;
        }

        if ($notificationLevel !== null && in_array($notificationLevel, ChannelUser::levels(), true)) {
            $membership->notification_level = $notificationLevel;
        }

        if ($muted !== null) {
            $membership->muted = $muted;

            // Silenciar zera as pendências para a barra lateral assentar na hora.
            if ($muted) {
                $membership->unread_count = 0;
                $membership->unread_mentions_count = 0;
            }
        }

        $membership->save();

        // A linha veio de consulta própria e a instância pode ter outro objeto da
        // mesma membership; descarta para a próxima leitura não usar o valor antigo.
        $channel->forgetMembership($user);

        return $membership;
    }

    /**
     * A membership ativa do usuário, criada na hora para quem um canal para todos
     * inclui sem linha, na primeira vez que há algo a guardar. Null para quem o
     * canal não inclui.
     *
     * Começa em dia com o canal, como quem volta, e em silêncio: a sala nunca viu a chegada.
     */
    public function ensure(Channel $channel, User $user): ?ChannelUser
    {
        $membership = $channel->membershipFor($user);

        if ($membership !== null || ! $channel->includes($user)) {
            return $membership;
        }

        $membership = $this->join($channel, $user);
        $membership->markReadUpTo($channel->last_message_id);
        $membership->save();

        return $membership;
    }

    /**
     * Tira alguém de um canal para todos em que estava sem linha: a linha de saída
     * é o que o mantém fora. Sem anúncio, pois a chegada também não teve.
     */
    protected function optOut(Channel $channel, User $user): void
    {
        if (! $channel->isForEveryone() || $channel->hasLeft($user)) {
            return;
        }

        $membership = new ChannelUser();
        $membership->channel_id = $channel->id;
        $membership->user_id = $user->id;
        $membership->notification_level = ChannelUser::LEVEL_MENTIONS;
        $membership->following = false;
        $membership->left_at = Carbon::now();
        $membership->save();

        $channel->forgetMembership($user);
    }
}
