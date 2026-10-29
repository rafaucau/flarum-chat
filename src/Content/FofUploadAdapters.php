<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Content;

use Flarum\Frontend\Document;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Storage\UploadStorage;

/**
 * Adapters do fof/upload disponíveis para anexos, para o dropdown do admin.
 * Só no frontend admin e com o fof/upload ativo; envia apenas as chaves, nunca
 * bucket, região ou credencial.
 */
class FofUploadAdapters
{
    public function __construct(
        protected UploadStorage $storage,
        protected LoggerInterface $log
    ) {
    }

    public function __invoke(Document $document): void
    {
        $keys = [];

        try {
            if ($this->storage->available()) {
                $keys = $this->storage->fof()->adapterKeys();
            }
        } catch (\Throwable $e) {
            // Adapter com pacote meio instalado não pode derrubar o painel.
            $this->log->warning('[ramon-chat] could not list fof/upload adapters: '.$e->getMessage());
        }

        $document->payload['ramonChatFofUploadAdapters'] = $keys;
    }
}
