<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Audit;

use Flarum\Audit\AuditLogger;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Event\ChannelWasArchived;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Event\ChannelWasDeleted;
use Ramon\Chat\Event\InviteWasCancelled;
use Ramon\Chat\Event\InviteWasDeclined;
use Ramon\Chat\Event\MessagePinToggled;
use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Event\MessageWasMoved;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Event\UserWasInvited;

/**
 * Integração com o flarum/audit via `Audit::using()` dentro de um Conditional:
 * a classe referencia o AuditLogger e não pode carregar sem ele.
 *
 * Registra só moderação e configuração (canais, remoção/fixação de mensagem
 * alheia, movimentação, membros, em especial entradas ocultas). Conversa comum
 * não entra: inundaria o log e criaria uma cópia permanente de canais privados.
 */
class AuditIntegration
{
    /**
     * Declarados para o flarum/audit listar e filtrar no admin.
     *
     * @var string[]
     */
    public static array $actions = [
        'chat.channel_created',
        'chat.channel_status_changed',
        'chat.channel_archived',
        'chat.channel_deleted',
        'chat.message_deleted',
        'chat.message_pinned',
        'chat.message_unpinned',
        'chat.messages_moved',
        'chat.user_joined',
        'chat.user_joined_hidden',
        'chat.user_left',
        'chat.user_invited',
        'chat.invite_declined',
        'chat.invite_cancelled',
    ];

    public function __invoke(Container $container): void
    {
        $events = $container->make(Dispatcher::class);

        $events->listen(ChannelWasCreated::class, [$this, 'channelCreated']);
        $events->listen(ChannelStatusChanged::class, [$this, 'channelStatusChanged']);
        $events->listen(ChannelWasArchived::class, [$this, 'channelArchived']);
        $events->listen(ChannelWasDeleted::class, [$this, 'channelDeleted']);
        $events->listen(MessageWasDeleted::class, [$this, 'messageDeleted']);
        $events->listen(MessagePinToggled::class, [$this, 'messagePinToggled']);
        $events->listen(MessageWasMoved::class, [$this, 'messagesMoved']);
        $events->listen(UserJoinedChannel::class, [$this, 'userJoined']);
        $events->listen(UserLeftChannel::class, [$this, 'userLeft']);
        $events->listen(UserWasInvited::class, [$this, 'userInvited']);
        $events->listen(InviteWasDeclined::class, [$this, 'inviteDeclined']);
        $events->listen(InviteWasCancelled::class, [$this, 'inviteCancelled']);
    }

    public function channelCreated(ChannelWasCreated $event): void
    {
        $this->log($event->actor, 'chat.channel_created', [
            'channel_id'   => $event->channel->id,
            'channel_name' => $event->channel->name,
            'is_private'   => (bool) $event->channel->is_private,
        ]);
    }

    public function channelStatusChanged(ChannelStatusChanged $event): void
    {
        $this->log($event->actor, 'chat.channel_status_changed', [
            'channel_id' => $event->channel->id,
            'from'       => $event->previousStatus,
            'to'         => $event->channel->status,
        ]);
    }

    public function channelArchived(ChannelWasArchived $event): void
    {
        $this->log($event->actor, 'chat.channel_archived', [
            'channel_id'    => $event->channel->id,
            'discussion_id' => $event->discussion->id,
        ]);
    }

    public function channelDeleted(ChannelWasDeleted $event): void
    {
        $this->log($event->actor, 'chat.channel_deleted', [
            'channel_id'   => $event->channel->id,
            'channel_name' => $event->channel->name,
        ]);
    }

    public function messageDeleted(MessageWasDeleted $event): void
    {
        // Só quando alguém remove mensagem alheia; apagar a própria não é moderação.
        if ($event->actor === null || $event->actor->id === $event->message->user_id) {
            return;
        }

        $this->log($event->actor, 'chat.message_deleted', [
            'message_id' => $event->message->id,
            'channel_id' => $event->message->channel_id,
            'author_id'  => $event->message->user_id,
        ]);
    }

    public function messagePinToggled(MessagePinToggled $event): void
    {
        $this->log(
            $event->actor,
            $event->message->isPinned() ? 'chat.message_pinned' : 'chat.message_unpinned',
            [
                'message_id' => $event->message->id,
                'channel_id' => $event->message->channel_id,
            ]
        );
    }

    public function messagesMoved(MessageWasMoved $event): void
    {
        $this->log($event->actor, 'chat.messages_moved', [
            'message_id' => $event->message->id,
            'from'       => $event->from->id,
            'to'         => $event->to->id,
        ]);
    }

    public function userJoined(UserJoinedChannel $event): void
    {
        // Entrada oculta tem ação própria: ninguém no canal a vê, só o log de auditoria.
        $this->log($event->actor, $event->hidden ? 'chat.user_joined_hidden' : 'chat.user_joined', [
            'channel_id' => $event->channel->id,
            'user_id'    => $event->user->id,
        ]);
    }

    public function userLeft(UserLeftChannel $event): void
    {
        $this->log($event->actor, 'chat.user_left', [
            'channel_id' => $event->channel->id,
            'user_id'    => $event->user->id,
        ]);
    }

    public function userInvited(UserWasInvited $event): void
    {
        $this->log($event->inviter, 'chat.user_invited', [
            'channel_id' => $event->channel->id,
            'user_id'    => $event->user->id,
        ]);
    }

    public function inviteDeclined(InviteWasDeclined $event): void
    {
        $this->log($event->user, 'chat.invite_declined', [
            'channel_id' => $event->channel->id,
            'inviter_id' => $event->inviter?->id,
        ]);
    }

    public function inviteCancelled(InviteWasCancelled $event): void
    {
        $this->log($event->actor, 'chat.invite_cancelled', [
            'channel_id' => $event->channel->id,
            'user_id'    => $event->user->id,
        ]);
    }

    /**
     * Alguns disparam de jobs e comandos, sem ator da requisição; por isso é
     * atribuído explicitamente, como faz a integração do flarum/gdpr.
     */
    protected function log(?object $actor, string $action, array $meta): void
    {
        AuditLogger::$actor = $actor;

        AuditLogger::log($action, $meta);
    }
}
