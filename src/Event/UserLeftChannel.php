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

class UserLeftChannel
{
    public function __construct(
        public Channel $channel,
        public User $user,
        public ?User $actor = null,
        /**
         * Se a membership encerrada era oculta; espelha a flag de UserJoinedChannel.
         * Saída de membro oculto não é anunciada. Lido antes de fechar a linha,
         * porque depois não dá mais para distinguir.
         */
        public bool $hidden = false
    ) {
    }
}
