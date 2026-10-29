<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Event;

use Flarum\User\User;

/**
 * A fila de moderação mudou: denúncia aberta ou fechada.
 *
 * Não leva contagem: cada moderador vê mensagens diferentes e calcula a sua.
 * Leva os ids das mensagens afetadas, para quem já está vendo uma atualizá-la.
 */
class FlagsChanged
{
    /**
     * @param  int[]  $messageIds
     */
    public function __construct(
        public ?User $actor = null,
        public array $messageIds = []
    ) {
    }
}
