<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Onde ficam os bytes do anexo.
 *
 * `storage`: `local` ou `fof`. `storage_adapter` guarda o adapter usado, para
 * apagar no mesmo backend mesmo após o admin trocar. `remote_url` é o endereço
 * público devolvido. Linhas existentes ficam `local` pelo default.
 */
return [
    // O migrator do Flarum passa um schema Builder; tipar como ConnectionInterface
    // dá TypeError e aborta todas as migrations da extensão.
    'up' => function (Builder $schema) {
        $columns = [
            'storage'         => fn (Blueprint $table) => $table->string('storage', 16)->default('local')->after('is_private'),
            'storage_adapter' => fn (Blueprint $table) => $table->string('storage_adapter', 64)->nullable()->after('storage'),
            'remote_url'      => fn (Blueprint $table) => $table->text('remote_url')->nullable()->after('storage_adapter'),
        ];

        // Uma coluna por vez, cada uma com guarda, para poder repetir uma execução
        // interrompida.
        foreach ($columns as $name => $define) {
            if ($schema->hasColumn('chat_uploads', $name)) {
                continue;
            }

            $schema->table('chat_uploads', function (Blueprint $table) use ($define) {
                $define($table);
            });
        }
    },

    'down' => function (Builder $schema) {
        foreach (['remote_url', 'storage_adapter', 'storage'] as $name) {
            if (! $schema->hasColumn('chat_uploads', $name)) {
                continue;
            }

            $schema->table('chat_uploads', function (Blueprint $table) use ($name) {
                $table->dropColumn($name);
            });
        }
    },
];
