<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Flarum\User\User;
use Ramon\Chat\Channel;

/**
 * Sinais de digitação e presença. Fora do BroadcastListener porque é ligado
 * sempre (o TypingController sempre tem rota); sem flarum/realtime o
 * ChatBroadcaster não faz nada.
 */
class PresenceBroadcaster
{
    public const EVENT_TYPING = 'ramonChat.typing';

    public function __construct(
        protected ChatBroadcaster $broadcaster
    ) {
    }

    public function typing(Channel $channel, User $actor, bool $typing = true): void
    {
        $this->broadcaster->toChannelMembers(
            $channel,
            self::EVENT_TYPING,
            [
                'channelId' => (int) $channel->id,
                'userId'    => (int) $actor->id,
                'username'  => $actor->display_name,
                'typing'    => $typing,
                // O cliente expira o indicador sozinho: o evento de "parou de
                // digitar" pode se perder.
                'expiresIn' => 6,
            ],
            (int) $actor->id
        );
    }
}
