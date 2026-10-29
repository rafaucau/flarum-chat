<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Carbon\Carbon;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Ramon\Chat\Event\FlagsChanged;
use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\MessageFlag;

/**
 * Fecha as denúncias abertas de uma mensagem quando ela é apagada: a exclusão
 * é a resolução.
 *
 * As linhas são marcadas, não removidas: fazem parte do histórico do autor.
 */
class ResolveFlagsOnModeration
{
    public function __construct(
        protected Events $events
    ) {
    }

    public function handle(MessageWasDeleted $event): void
    {
        $closed = MessageFlag::query()
            ->where('message_id', $event->message->id)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at'    => Carbon::now(),
                // Null quando a exclusão não tem ator (ex.: retenção). A denúncia fecha
                // mesmo assim, sem a quem atribuir.
                'resolved_by_id' => $event->actor?->id,
            ]);

        // A maioria das exclusões não fecha nada e não deve custar recontagem a
        // cada moderador.
        if ($closed > 0) {
            $this->events->dispatch(new FlagsChanged($event->actor, [(int) $event->message->id]));
        }
    }
}
