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
 * "X tornou você moderador de #canal."
 *
 * Só ids: o nome vem do canal, carregado como subject e filtrado por
 * visibilidade. O id do promovido evita que o NotificationSyncer confunda
 * notificações de pessoas diferentes.
 */
class ModeratorPromotedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(
        public Channel $channel,
        public User $user,
        public ?User $actor = null
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->channel;
    }

    public function getFromUser(): ?User
    {
        return $this->actor;
    }

    public function getData(): array
    {
        return [
            'channelId' => (int) $this->channel->id,
            'userId'    => (int) $this->user->id,
        ];
    }

    public static function getType(): string
    {
        return 'chatModeratorPromoted';
    }

    public static function getSubjectModel(): string
    {
        return Channel::class;
    }
}
