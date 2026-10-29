<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('chat_channels', function (Blueprint $table) {
            $table->increments('id');

            // 'category': ligado a uma tag, herda as permissões dela.
            // 'direct': DM 1:1 ou em grupo, com lista explícita de membros.
            $table->string('type', 20)->default('category');

            $table->string('name', 100)->nullable();
            $table->string('slug', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('emoji', 60)->nullable();

            // Tag vinculada (flarum/tags). Null em canais diretos ou criados sem tags.
            $table->unsignedInteger('tag_id')->nullable();

            // open | closed | archived
            $table->string('status', 20)->default('open');

            $table->boolean('threading_enabled')->default(false);

            // Inclui todo usuário atual e futuro.
            $table->boolean('auto_join')->default(false);

            // Silencia @here/@all no canal todo.
            $table->boolean('allow_channel_wide_mentions')->default(true);

            $table->unsignedInteger('creator_id')->nullable();

            // Contadores desnormalizados, mantidos no envio, para a lista de canais
            // não agregar por linha.
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('user_count')->default(0);
            $table->unsignedInteger('last_message_id')->nullable();
            $table->dateTime('last_message_at')->nullable();

            // Discussão onde a transcrição foi postada ao arquivar.
            $table->unsignedInteger('archived_discussion_id')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->unsignedInteger('archived_by_id')->nullable();

            $table->timestamps();
            $table->dateTime('deleted_at')->nullable();
            $table->unsignedInteger('deleted_by_id')->nullable();

            $table->unique('slug');
            $table->index(['type', 'status']);
            $table->index('tag_id');
            $table->index('last_message_at');

            // Sem FK em tag_id: flarum/tags é opcional e a tabela `tags` pode não existir.
            // Vínculos órfãos são podados por Listener\PruneOrphanedChannels.
            $table->foreign('creator_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('archived_by_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_channels');
    },
];
