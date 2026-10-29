<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\Fixtures;

use FoF\Upload\Adapters\Flysystem;
use FoF\Upload\File;

/**
 * Adapter do fof/upload que se comporta como um bucket num CDN: Flysystem por
 * baixo e URL num host próprio. Os bytes caem no diretório do adapter
 * Flysystem embrulhado, para o teste conferir no disco.
 *
 * Só é carregado por testes que já checaram que o fof/upload está instalado.
 */
class FakeBucketAdapter extends Flysystem
{
    public const HOST = 'https://bucket.chat.test';

    /**
     * Quando ligado, recusa toda escrita como uma queda do backend é reportada:
     * `upload()` responde false.
     */
    public bool $refuse = false;

    public function upload(File $file, $upload = null, $contents = null)
    {
        if ($this->refuse) {
            return false;
        }

        return parent::upload($file, $upload, $contents);
    }

    protected function generateUrl(File $file): void
    {
        $file->url = self::HOST.'/'.$file->path;
    }
}
