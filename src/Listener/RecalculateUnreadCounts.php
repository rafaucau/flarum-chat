<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Event\MessageWasMoved;
use Ramon\Chat\Service\UnreadTracker;

/**
 * Mantém as não lidas certas após apagar ou mover uma mensagem.
 *
 * Só mudam, em um, as memberships que ainda contavam a mensagem: quem não leu
 * além dela. Recontar todos os membros seria proporcional ao tamanho do canal.
 * Ver UnreadTracker.
 */
class RecalculateUnreadCounts
{
    public function __construct(
        protected UnreadTracker $unread
    ) {
    }

    public function handle(MessageWasDeleted|MessageWasMoved $event): void
    {
        $message = $event->message;

        if ($event instanceof MessageWasMoved) {
            if ((int) $event->from->id === (int) $event->to->id) {
                return;
            }

            $this->unread->forgetMessage($message, (int) $event->from->id);
            $this->unread->addMovedMessage($message, (int) $event->to->id);

            return;
        }

        $this->unread->forgetMessage($message, (int) $message->channel_id);
    }
}
