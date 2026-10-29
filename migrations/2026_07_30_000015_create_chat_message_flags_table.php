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
 * Denúncias de mensagens do chat.
 *
 * Tabela própria, não `flags`: `flags_post_id_foreign` é FK não nula para `posts`
 * e recusaria o id. Alterar o schema de outra extensão seria desfeito no update dela.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('chat_message_flags', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('message_id');
            $table->unsignedInteger('user_id')->nullable();

            // Chave de MessageFlag::REASONS. String, não enum, para novos motivos não
            // exigirem migration.
            $table->string('reason', 30);

            // Opcional, exceto para `other`.
            $table->text('detail')->nullable();

            // Preenchido ao resolver. Mantido, não apagado: o histórico distingue uma
            // mensagem denunciada de novo de uma denunciada só uma vez.
            $table->dateTime('resolved_at')->nullable();
            $table->unsignedInteger('resolved_by_id')->nullable();

            $table->dateTime('created_at')->nullable();

            // Uma denúncia por pessoa por mensagem, para ninguém inflar a contagem.
            $table->unique(['message_id', 'user_id']);

            // Query da fila: abertas, mais novas primeiro.
            $table->index(['resolved_at', 'created_at']);

            $table->foreign('message_id')->references('id')->on('chat_messages')->cascadeOnDelete();

            // A denúncia sobrevive à conta: apagar a conta não pode apagar a evidência.
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('chat_message_flags');
    },
];
