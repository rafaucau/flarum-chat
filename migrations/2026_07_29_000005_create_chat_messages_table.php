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
        $schema->create('chat_messages', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('channel_id');
            $table->unsignedInteger('user_id')->nullable();

            // A raiz também tem thread_id, então a thread inteira sai numa query.
            $table->unsignedInteger('thread_id')->nullable();

            // Sequência por canal. Ordenação e paginação por cursor estáveis mesmo com
            // edições, soft deletes e mensagens movidas entre canais, o que o id não garante.
            $table->unsignedInteger('number')->nullable();

            // Resposta inline (diferente de thread).
            $table->unsignedInteger('reply_to_id')->nullable();

            $table->mediumText('content')->nullable();

            // text | system. Mensagens de sistema guardam chave + JSON, não texto do usuário.
            $table->string('type', 20)->default('text');
            $table->string('system_key', 60)->nullable();
            $table->text('system_data')->nullable();

            // Webhook que entregou a mensagem.
            $table->unsignedInteger('webhook_id')->nullable();

            $table->dateTime('edited_at')->nullable();
            $table->unsignedInteger('edited_by_id')->nullable();

            $table->dateTime('deleted_at')->nullable();
            $table->unsignedInteger('deleted_by_id')->nullable();

            $table->timestamps();

            $table->unique(['channel_id', 'number']);
            $table->index(['channel_id', 'created_at']);
            $table->index(['thread_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('reply_to_id');

            $table->foreign('channel_id')->references('id')->on('chat_channels')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('thread_id')->references('id')->on('chat_threads')->nullOnDelete();
            $table->foreign('reply_to_id')->references('id')->on('chat_messages')->nullOnDelete();
            $table->foreign('edited_by_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_messages');
    },
];
