<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Event;

use Flarum\User\User;
use Ramon\Chat\Message;

/**
 * Mensagem fixada ou desafixada; `$message->isPinned()` diz qual.
 *
 * Separado de MessageWasEdited: o conteúdo não mudou, então histórico de
 * revisões e reparse de menções não devem rodar.
 */
class MessagePinToggled
{
    public function __construct(
        public Message $message,
        public ?User $actor = null
    ) {
    }
}
