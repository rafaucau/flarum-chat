<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Content;

use Flarum\Frontend\Document;
use Illuminate\Contracts\Queue\Queue;
use Ramon\Chat\Realtime\QueueKind;

/**
 * Tipo de fila do fórum (sync, database ou outro) para o card de realtime do
 * admin, que só oferece a opção de fila com worker contínuo. Só o tipo sai do
 * servidor, nenhum detalhe de conexão.
 */
class QueueDriver
{
    public function __construct(protected Queue $queue)
    {
    }

    public function __invoke(Document $document): void
    {
        $document->payload['ramonChatQueueKind'] = QueueKind::of($this->queue);
    }
}
