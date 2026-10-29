<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Illuminate\Support\Collection;
use Ramon\Chat\Channel;
use Ramon\Chat\Message;

/**
 * Renderiza mensagens como transcrição citável, para "Citar na discussão",
 * "Copiar" e o arquivamento. Usa markup que o formatter do Flarum já entende.
 */
class TranscriptRenderer
{
    /**
     * @param  Collection<int, Message>|Message[]  $messages
     */
    public function render(iterable $messages, ?Channel $channel = null): string
    {
        $messages = collect($messages)
            ->filter(fn (Message $m) => ! $m->isDeleted() && ! $m->isSystem())
            ->sortBy('id')
            ->values();

        if ($messages->isEmpty()) {
            return '';
        }

        $channel ??= $messages->first()->channel;

        $lines = [];

        if ($channel !== null && $channel->name !== null) {
            $lines[] = '**'.$this->escape($channel->name).'**';
            $lines[] = '';
        }

        foreach ($messages as $message) {
            $author = $message->user->display_name
                ?? $message->webhook->username
                ?? '?';

            $timestamp = $message->created_at?->toIso8601String() ?? '';

            // Um bloco de citação por mensagem, para a autoria continuar clara depois de colada.
            $lines[] = '> **'.$this->escape($author).'** · '.$timestamp;

            foreach (preg_split('/\R/', (string) $message->content) as $contentLine) {
                $lines[] = '> '.$contentLine;
            }

            foreach ($message->uploads as $upload) {
                $lines[] = '> '.($upload->isImage() ? '!' : '').'['.$this->escape($upload->file_name).']('.$upload->url().')';
            }

            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Forma em texto simples do "Copiar", onde markup seria ruído.
     *
     * @param  Collection<int, Message>|Message[]  $messages
     */
    public function renderPlain(iterable $messages): string
    {
        $lines = [];

        foreach (collect($messages)->sortBy('id') as $message) {
            if ($message->isDeleted() || $message->isSystem()) {
                continue;
            }

            $author = $message->user->display_name ?? $message->webhook->username ?? '?';
            $time = $message->created_at?->format('Y-m-d H:i') ?? '';

            $lines[] = "[$time] $author: ".strip_tags((string) $message->content);
        }

        return implode("\n", $lines);
    }

    /**
     * Caracteres de controle de Markdown/BBCode que deixariam um nome exibido
     * escapar da estrutura da citação.
     */
    protected function escape(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\*', '\_', '\[', '\]', '\`'], $value);
    }
}
