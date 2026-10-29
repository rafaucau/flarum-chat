<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Foundation\Paths;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Database\Schema\Builder;

// Declarada antes do `return`: função dentro de condição não sofre hoisting
// e o `return` do arquivo encerra antes de qualquer coisa abaixo.
if (! function_exists('chat_uploads_relocate')) {
    /**
     * Move um arquivo entre as duas raízes.
     *
     * Paths são gerados pelo servidor, mas uma linha fora do formato é recusada
     * mesmo assim. Já no destino conta como movido; ausente nos dois, linha e
     * arquivo ficam como estão.
     */
    function chat_uploads_relocate(string $path, string $fromRoot, string $toRoot): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return false;
        }

        $source = $fromRoot.'/'.$path;
        $target = $toRoot.'/'.$path;

        if (is_file($target)) {
            if (is_file($source)) {
                @unlink($source);
            }

            return true;
        }

        if (! is_file($source)) {
            return false;
        }

        $dir = dirname($target);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }

        return @rename($source, $target);
    }
}

/**
 * Tira do disco público os anexos já postados em canais privados e diretos.
 *
 * Antes do disco privado, tudo ia para `public/assets/chat` e era legível por
 * quem tivesse a URL. Move cada arquivo e só marca a linha quando o arquivo já
 * está onde a flag diz.
 *
 * `rename` nativo, não os discos: os extenders (e os discos) podem não estar
 * registrados durante as migrations. Os paths vêm de Paths, como nos discos.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('chat_uploads') || ! $schema->hasColumn('chat_uploads', 'is_private')) {
            return;
        }

        $paths = resolve(Paths::class);
        $from = $paths->public.'/assets/chat';
        $to = $paths->storage.'/chat-uploads';

        // Tags restritas também contam, quando a tabela de tags existe.
        $tags = $schema->hasTable('tags');

        $schema->getConnection()->table('chat_uploads')
            ->join('chat_messages', 'chat_messages.id', '=', 'chat_uploads.message_id')
            ->join('chat_channels', 'chat_channels.id', '=', 'chat_messages.channel_id')
            ->when($tags, fn (Query $query) => $query->leftJoin('tags', 'tags.id', '=', 'chat_channels.tag_id'))
            ->where('chat_uploads.is_private', 0)
            ->where(function (Query $query) use ($tags) {
                $query->where('chat_channels.type', 'direct')
                    ->orWhere('chat_channels.is_private', 1)
                    ->when($tags, fn (Query $query) => $query->orWhere('tags.is_restricted', 1));
            })
            ->select(['chat_uploads.id', 'chat_uploads.path'])
            ->orderBy('chat_uploads.id')
            ->chunkById(200, function ($uploads) use ($schema, $from, $to) {
                foreach ($uploads as $upload) {
                    if (! chat_uploads_relocate($upload->path, $from, $to)) {
                        continue;
                    }

                    $schema->getConnection()->table('chat_uploads')
                        ->where('id', $upload->id)
                        ->update(['is_private' => 1]);
                }
            }, 'chat_uploads.id', 'id');
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasTable('chat_uploads') || ! $schema->hasColumn('chat_uploads', 'is_private')) {
            return;
        }

        $paths = resolve(Paths::class);
        $from = $paths->storage.'/chat-uploads';
        $to = $paths->public.'/assets/chat';

        $schema->getConnection()->table('chat_uploads')
            ->where('is_private', 1)
            ->select(['id', 'path'])
            ->orderBy('id')
            ->chunkById(200, function ($uploads) use ($schema, $from, $to) {
                foreach ($uploads as $upload) {
                    if (! chat_uploads_relocate($upload->path, $from, $to)) {
                        continue;
                    }

                    $schema->getConnection()->table('chat_uploads')
                        ->where('id', $upload->id)
                        ->update(['is_private' => 0]);
                }
            });
    },
];
