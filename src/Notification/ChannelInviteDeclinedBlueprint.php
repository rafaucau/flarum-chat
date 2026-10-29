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
 * "X recusou seu convite para #canal." Vai ao dono e a quem convidou.
 */
class ChannelInviteDeclinedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(
        public Channel $channel,
        public User $decliner
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->channel;
    }

    public function getFromUser(): ?User
    {
        return $this->decliner;
    }

    /**
     * Só ids e o nome; o nome vai junto porque quem saiu de um canal privado não
     * consegue lê-lo pela lista de notificações.
     */
    public function getData(): array
    {
        return [
            'channelId'   => (int) $this->channel->id,
            'channelName' => (string) ($this->channel->name ?? ''),
            'isPrivate'   => (bool) $this->channel->is_private,
        ];
    }

    public static function getType(): string
    {
        return 'chatChannelInviteDeclined';
    }

    public static function getSubjectModel(): string
    {
        return Channel::class;
    }
}
