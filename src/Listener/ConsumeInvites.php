<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Illuminate\Contracts\Events\Dispatcher;
use Ramon\Chat\Event\InviteWasCancelled;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Service\InvitationManager;

/**
 * Encerra o convite pendente de quem sai ou é removido do canal.
 *
 * Sem isso, quem entrou por outro caminho (respondendo na categoria) e foi
 * removido voltaria ao canal privado aceitando o convite antigo.
 */
class ConsumeInvites
{
    public function __construct(
        protected InvitationManager $invitations,
        protected Dispatcher $events
    ) {
    }

    public function handle(UserLeftChannel $event): void
    {
        $invite = $this->invitations->cancel($event->channel, $event->user);

        // Fecha a notificação do convite e avisa a aba aberta do convidado.
        if ($invite !== null) {
            $this->events->dispatch(new InviteWasCancelled($event->channel, $event->user, $event->actor ?? $event->user, $invite->inviter));
        }
    }
}
