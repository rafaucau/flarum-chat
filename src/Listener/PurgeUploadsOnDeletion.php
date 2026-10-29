<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Storage\UploadStorage;
use Ramon\Chat\Upload;

/**
 * Tira do disco os anexos de uma mensagem apagada.
 *
 * O disco do chat é público (`public/assets/chat`, servido sem autorização),
 * então o arquivo continuaria acessível pela URL. Restaurar a mensagem traz o
 * texto, mas não os anexos: é a perda escolhida. As linhas saem junto para a
 * mensagem restaurada não mostrar imagens quebradas.
 */
class PurgeUploadsOnDeletion
{
    public function __construct(
        protected UploadStorage $storage
    ) {
    }

    public function handle(MessageWasDeleted $event): void
    {
        $uploads = Upload::query()
            ->where('message_id', $event->message->id)
            ->get();

        if ($uploads->isEmpty()) {
            return;
        }

        // Melhor esforço por arquivo: a linha sai de qualquer jeito, e parar deixaria
        // os outros anexos publicados. O resolver loga a falha.
        foreach ($uploads as $upload) {
            $this->storage->delete($upload);
        }

        Upload::query()->whereIn('id', $uploads->pluck('id')->all())->delete();
    }
}
