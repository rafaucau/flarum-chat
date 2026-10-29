<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage\Job;

use Flarum\Queue\AbstractJob;
use Ramon\Chat\Channel;
use Ramon\Chat\Service\UploadPrivacy;

/**
 * Tira do disco público os anexos de um canal que deixou de ser público (tag
 * restrita ou varredura horária). A regra é reavaliada na execução, então um canal
 * que voltou a ser público no meio-tempo fica como está.
 */
class PrivatizeChannelUploads extends AbstractJob
{
    public int $tries = 3;

    public function __construct(
        protected int $channelId
    ) {
        parent::__construct();
    }

    public function __invoke(UploadPrivacy $privacy): void
    {
        $channel = Channel::query()->find($this->channelId);

        if ($channel === null || ! UploadPrivacy::requiredFor($channel)) {
            return;
        }

        $privacy->privatizeChannel($channel);
    }
}
