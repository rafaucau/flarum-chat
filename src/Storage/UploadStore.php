<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage;

use Ramon\Chat\Upload;

/**
 * Onde ficam os bytes de um anexo. Quem escolhe a implementação é o
 * UploadStorage. O store só move bytes: não decide privacidade
 * (Service\UploadPrivacy) nem salva a linha.
 */
interface UploadStore
{
    /**
     * Grava e preenche `path`, `storage`, `storage_adapter` e `remote_url`.
     * `is_private` e `mime_type` já precisam estar definidos: decidem o destino.
     *
     * @param  resource  $stream
     *
     * @throws StorageFailed quando não foi possível gravar.
     */
    public function put(Upload $upload, $stream, string $extension): void;

    /**
     * Arquivo que já não existe não é falha.
     *
     * @throws StorageFailed quando o store recusou.
     */
    public function delete(Upload $upload): void;

    /**
     * Os bytes, ou null se o arquivo não pode ser achado ou lido.
     *
     * @return resource|null
     */
    public function readStream(Upload $upload);

    /**
     * Tamanho em bytes, ou null se o store não sabe sem baixar o arquivo.
     */
    public function size(Upload $upload): ?int;
}
