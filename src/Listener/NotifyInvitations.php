<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\InviteWasCancelled;
use Ramon\Chat\Event\InviteWasDeclined;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UsersWereInvited;
use Ramon\Chat\Notification\ChannelInviteBlueprint;
use Ramon\Chat\Notification\ChannelInviteDeclinedBlueprint;
use Ramon\Chat\Service\InvitationManager;

/**
 * Mantém a notificação de convite em sincronia com a tabela de convites.
 *
 * O NotificationSyncer recebe a lista completa de quem deve ter a notificação
 * (tipo + canal + quem convidou) e cria ou apaga conforme a diferença. Passar
 * os convites pendentes faz o aceito, recusado ou cancelado sumir do sino.
 */
class NotifyInvitations
{
    public function __construct(
        protected NotificationSyncer $notifications,
        protected InvitationManager $invitations
    ) {
    }

    public function whenInvited(UsersWereInvited $event): void
    {
        $this->syncInvites($event->channel, $event->inviter);
    }

    public function whenJoined(UserJoinedChannel $event): void
    {
        if (! $event->acceptedInvite) {
            return;
        }

        $this->syncInvites($event->channel, $event->invitedBy);
    }

    public function whenDeclined(InviteWasDeclined $event): void
    {
        $this->syncInvites($event->channel, $event->inviter);

        $recipients = [];

        foreach ([$event->channel->creator, $event->inviter] as $user) {
            if ($user === null || (int) $user->id === (int) $event->user->id) {
                continue;
            }

            $recipients[(int) $user->id] = $user;
        }

        if ($recipients === []) {
            return;
        }

        $this->notifications->sync(
            new ChannelInviteDeclinedBlueprint($event->channel, $event->user),
            array_values($recipients)
        );
    }

    public function whenCancelled(InviteWasCancelled $event): void
    {
        $this->syncInvites($event->channel, $event->inviter);
    }

    protected function syncInvites(Channel $channel, ?User $inviter): void
    {
        $this->notifications->sync(
            new ChannelInviteBlueprint($channel, $inviter),
            $this->invitations->pendingUsersInvitedBy($channel, $inviter)
        );
    }
}
