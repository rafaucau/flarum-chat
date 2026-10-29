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

/**
 * O convidado recusou. `$inviter` é quem convidou, se a conta ainda existir;
 * quem é avisado é decidido em Listener\NotifyInvitations.
 */
class InviteWasDeclined
{
    public function __construct(
        public Channel $channel,
        public User $user,
        public ?User $inviter = null
    ) {
    }
}
