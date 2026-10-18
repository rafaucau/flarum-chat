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
 * One request's invites, after they were all created.
 *
 * UserWasInvited still fires per invite, for the listeners that work person
 * by person. This one exists for whoever needs the batch as a whole — the
 * notification sync, which rebuilds the inviter's whole list each time and
 * would otherwise run once per person asked.
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
