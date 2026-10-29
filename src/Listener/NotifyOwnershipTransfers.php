<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Notification\Notification;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelTransfer;
use Ramon\Chat\Event\ChannelOwnershipTransferred;
use Ramon\Chat\Event\OwnershipTransferEnded;
use Ramon\Chat\Event\OwnershipTransferRequested;
use Ramon\Chat\Notification\OwnershipInheritedBlueprint;
use Ramon\Chat\Notification\OwnershipTransferBlueprint;
use Ramon\Chat\Notification\OwnershipTransferDeclinedBlueprint;

/**
 * Mantém o pedido de transferência no sino do destinatário enquanto está
 * aberto, avisa o autor da recusa e avisa quem herdou um canal.
 *
 * Cada destinatário é checado contra a visibilidade do canal, o subject da
 * notificação.
 */
class NotifyOwnershipTransfers
{
    public function __construct(
        protected NotificationSyncer $notifications
    ) {
    }

    public function whenRequested(OwnershipTransferRequested $event): void
    {
        if (! $event->to->can('view', $event->channel)) {
            return;
        }

        $this->notifications->sync(
            new OwnershipTransferBlueprint($event->channel, $event->transfer, $event->from),
            [$event->to]
        );
    }

    public function whenEnded(OwnershipTransferEnded $event): void
    {
        $this->withdraw($event->channel, $event->transfer);

        if ($event->reason !== OwnershipTransferEnded::DECLINED || $event->actor === null) {
            return;
        }

        /** @var User|null $from */
        $from = User::query()->find($event->transfer->from_user_id);

        if ($from === null || (int) $from->id === (int) $event->actor->id || ! $from->can('view', $event->channel)) {
            return;
        }

        $this->notifications->sync(
            new OwnershipTransferDeclinedBlueprint($event->channel, $event->transfer, $event->actor),
            [$from]
        );
    }

    /**
     * Transferência aceita só tira o pedido do sino. Sucessão avisa o novo dono,
     * que não pediu nada e precisa saber que agora responde pelo canal.
     */
    public function whenTransferred(ChannelOwnershipTransferred $event): void
    {
        if ($event->transfer !== null) {
            $this->withdraw($event->channel, $event->transfer);
        }

        if (! $event->inherited || ! $event->newOwner->can('view', $event->channel)) {
            return;
        }

        $this->notifications->sync(
            new OwnershipInheritedBlueprint($event->channel, $event->newOwner, $event->previousOwner),
            [$event->newOwner]
        );
    }

    /**
     * Tira o pedido do sino de quem ia recebê-lo. Pela transferência, não pelo
     * NotificationSyncer: o autor pode não existir mais, e há só uma por canal.
     */
    protected function withdraw(Channel $channel, ChannelTransfer $transfer): void
    {
        Notification::query()
            ->where('user_id', $transfer->to_user_id)
            ->where('type', OwnershipTransferBlueprint::getType())
            ->where('subject_id', $channel->id)
            ->update(['is_deleted' => true]);
    }
}
