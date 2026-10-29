<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;
use Ramon\Chat\Service\UnreadTracker;

/**
 * Contadores de não lidas no recurso de usuário, expostos só ao próprio usuário.
 * Contam pelos canais que o ator vê agora; o contador gravado sozinho não basta
 * (ver UnreadTracker::visibleMemberships()).
 */
class UserResourceFields
{
    public function __construct(
        protected UnreadTracker $unread
    ) {
    }

    public function __invoke(): array
    {
        $isSelf = fn (User $user, Context $context) => $context->getActor()->is($user);

        return [
            Schema\Integer::make('chatUnreadChannelsCount')
                ->visible($isSelf)
                ->get(fn (User $user) => $this->unread->totalUnreadFor($user)),

            // Conta mensagens, não canais: o cabeçalho do drawer mostra o quanto
            // a pessoa está atrasada.
            Schema\Integer::make('chatUnreadMessagesCount')
                ->visible($isSelf)
                ->get(fn (User $user) => $this->unread->totalUnreadMessagesFor($user)),

            Schema\Integer::make('chatUnreadMentionsCount')
                ->visible($isSelf)
                ->get(fn (User $user) => $this->unread->totalUnreadMentionsFor($user)),
        ];
    }
}
