<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage;

use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Upload;

/**
 * Escolhe o store. Arquivo privado vai sempre para o disco privado do chat, porque
 * o storage do fof/upload é público; o público vai pelo fof/upload só se o admin
 * escolheu e a extensão está ligada. Arquivo existente segue o `storage` da linha.
 */
class UploadStorage
{
    public const SETTING = 'ramon-chat.upload_storage';
    public const SETTING_FOF = 'fof-upload';

    protected ?LocalUploadStore $local = null;
    protected ?FofUploadStore $fof = null;

    public function __construct(
        protected Container $container,
        protected SettingsRepositoryInterface $settings,
        protected ExtensionManager $extensions,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Se o fof/upload pode ser usado agora.
     */
    public function available(): bool
    {
        return FofUploadStore::supported() && $this->extensions->isEnabled('fof-upload');
    }

    /**
     * Se arquivos públicos novos devem ir pelo fof/upload.
     */
    public function usesFof(): bool
    {
        return $this->settings->get(self::SETTING) === self::SETTING_FOF && $this->available();
    }

    public function forNew(bool $private): UploadStore
    {
        if ($private || ! $this->usesFof()) {
            return $this->local();
        }

        return $this->fof();
    }

    /**
     * @throws StorageFailed quando o arquivo está no fof/upload e ele não está
     *                       mais disponível.
     */
    public function for(Upload $upload): UploadStore
    {
        if (! $upload->isRemote()) {
            return $this->local();
        }

        if (! $this->available()) {
            throw new StorageFailed("Upload {$upload->id} is stored through fof/upload, which is not enabled.");
        }

        return $this->fof();
    }

    /**
     * Melhor esforço: quem chama remove a linha também, e um arquivo teimoso não
     * segura o lote. Log em nível error porque a cópia remota sem linha só sai à
     * mão.
     */
    public function delete(Upload $upload): bool
    {
        try {
            $this->for($upload)->delete($upload);

            return true;
        } catch (\Throwable $e) {
            $this->log->error('[ramon-chat] could not delete an upload\'s file', [
                'upload'  => $upload->id,
                'storage' => $upload->storage,
                'adapter' => $upload->storage_adapter,
                'path'    => $upload->path,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function local(): LocalUploadStore
    {
        return $this->local ??= $this->container->make(LocalUploadStore::class);
    }

    public function fof(): FofUploadStore
    {
        return $this->fof ??= $this->container->make(FofUploadStore::class);
    }
}
