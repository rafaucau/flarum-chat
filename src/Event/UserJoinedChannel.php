<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Event;

use Flarum\User\User;
use Ramon\Chat\Channel;

class UserJoinedChannel
{
    public function __construct(
        public Channel $channel,
        public User $user,
        public ?User $actor = null,
        /**
         * Entrada oculta: ninguém deve saber dela. Vai no evento porque, quando o
         * listener roda, a linha de membership já reflete o estado atual, não a transição.
         */
        public bool $hidden = false,
        /**
         * Entrada como efeito colateral (`auto_join_on_reply`), não anunciada para
         * não enterrar a conversa. Vai no evento porque o mesmo canal também recebe
         * entradas deliberadas, que são anunciadas.
         */
        public bool $automatic = false,
        /**
         * Entrada por convite. `invitedBy` é quem convidou, se a conta existir: o
         * aviso na sala responde ao convite e a notificação dele é encerrada.
         */
        public bool $acceptedInvite = false,
        public ?User $invitedBy = null
    ) {
    }
}
