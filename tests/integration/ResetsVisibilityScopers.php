<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Ramon\Chat\Channel;
use Ramon\Chat\Message;
use Ramon\Chat\Thread;
use Ramon\Chat\Upload;

/**
 * Começa cada teste com uma cópia de cada scoper de visibilidade do chat.
 *
 * `Extend\ModelVisibility` registra o scoper num registro estático do model, e
 * o flarum/testing sobe um app novo por teste sem limpá-lo: o centésimo teste
 * roda cem cópias de cada scoper. Como os escopos se aninham (upload → mensagem
 * → canal), as cópias se multiplicam e o SQLite acaba respondendo "too many SQL
 * variables".
 *
 * Limpar antes do boot deixa o mesmo conjunto de um fórum real. Discussion e
 * Post também: o core registra os deles a cada boot, e com o flarum/mentions a
 * contagem de notificações aninha os dois e cresce do mesmo jeito.
 */
trait ResetsVisibilityScopers
{
    protected function resetVisibilityScopers(): void
    {
        foreach ([Channel::class, Message::class, Thread::class, Upload::class, Discussion::class, Post::class] as $model) {
            $property = new \ReflectionProperty($model, 'visibilityScopers');
            $property->setValue(null, []);
        }
    }
}
