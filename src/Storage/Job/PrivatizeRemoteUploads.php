<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage\Job;

use Flarum\Queue\AbstractJob;
use Ramon\Chat\Service\UploadPrivacy;

/**
 * Traz para o disco privado os anexos do fof/upload de um canal que ficou privado.
 * As linhas já foram marcadas privadas; aqui só se movem os bytes e se apagam as
 * cópias públicas. Leva o id do canal, então o retry pega o que sobrou e rodar duas
 * vezes não faz mal.
 */
class PrivatizeRemoteUploads extends AbstractJob
{
    /**
     * Para um bucket fora do ar por pouco tempo.
     */
    public int $tries = 3;

    public function __construct(
        protected int $channelId
    ) {
        parent::__construct();
    }

    public function __invoke(UploadPrivacy $privacy): void
    {
        [$moved, $found] = $privacy->pullRemoteInChannel($this->channelId);

        // Cada falha já foi logada. Lança para a fila tentar o resto; na fila sync
        // o UploadPrivacy captura.
        if ($moved < $found) {
            throw new \RuntimeException(sprintf(
                'Moved %d of %d remote uploads in chat channel %d to the private disk.',
                $moved,
                $found,
                $this->channelId
            ));
        }
    }
}
