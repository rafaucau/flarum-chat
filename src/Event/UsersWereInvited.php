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
use Ramon\Chat\ChannelInvite;

/**
 * Os convites de uma requisição, depois de todos criados.
 *
 * UserWasInvited continua disparando por convite. Este serve à sincronização de
 * notificações, que reconstrói a lista inteira e rodaria uma vez por convidado.
 */
class UsersWereInvited
{
    /**
     * @param  ChannelInvite[]  $invites
     */
    public function __construct(
        public Channel $channel,
        public User $inviter,
        public array $invites
    ) {
    }
}
