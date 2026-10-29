<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;
use Ramon\Chat\Channel;

/**
 * "X convidou você para um canal", com aceitar e recusar na própria linha.
 *
 * Nada é feito até o convidado aceitar. Listener\NotifyInvitations mantém a
 * notificação em sincronia com os convites. Sem e-mail: convite não é urgente.
 */
/*
 * AlertableInterface não tem métodos, mas o AlertNotificationDriver do Flarum
 * exige a interface; sem ela a notificação some em silêncio.
 */
class ChannelInviteBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(
        public Channel $channel,
        public ?User $inviter = null
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->channel;
    }

    public function getFromUser(): ?User
    {
        return $this->inviter;
    }

    /**
     * O nome vai junto porque canal privado só é visível a membros, e quem saiu
     * não conseguiria carregá-lo na lista de notificações.
     */
    public function getData(): array
    {
        return [
            'channelId'   => $this->channel->id,
            'channelName' => $this->channel->name,
            'isPrivate'   => (bool) $this->channel->is_private,
        ];
    }

    public static function getType(): string
    {
        return 'chatChannelInvite';
    }

    public static function getSubjectModel(): string
    {
        return Channel::class;
    }
}
