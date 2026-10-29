<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit;

use Carbon\Carbon;
use Ramon\Chat\Message;
use Ramon\Chat\Thread;

/**
 * Os contadores de respostas do tópico.
 *
 * `noteReply` evita recontar o tópico inteiro a cada resposta (um COUNT que
 * cresce com o tópico). A conta parece óbvia, mas se divergir do que
 * `refreshMetadata` calcularia, o número de respostas na UI deixa de bater com
 * as mensagens sem aviso.
 */
class ThreadTest extends QueryTestCase
{
    protected function thread(array $attributes = []): Thread
    {
        $thread = new Thread();

        $thread->setRawAttributes(array_merge([
            'id'                  => 7,
            'channel_id'          => 1,
            'original_message_id' => 100,
            'replies_count'       => 0,
            'last_message_id'     => null,
            'last_message_at'     => null,
        ], $attributes), true);

        return $thread;
    }

    protected function message(int $id, string $createdAt = '2026-07-30 10:00:00'): Message
    {
        $message = new Message();

        $message->setRawAttributes([
            'id'         => $id,
            'channel_id' => 1,
            'thread_id'  => 7,
            'created_at' => $createdAt,
        ], true);

        return $message;
    }

    public function test_a_reply_advances_the_counters(): void
    {
        $thread = $this->thread();

        $thread->noteReply($this->message(101, '2026-07-30 10:05:00'));

        $this->assertSame(1, $thread->replies_count);
        $this->assertSame(101, (int) $thread->last_message_id);
        $this->assertInstanceOf(Carbon::class, $thread->last_message_at);
        $this->assertSame('2026-07-30 10:05:00', $thread->last_message_at->toDateTimeString());
    }

    public function test_replies_accumulate(): void
    {
        $thread = $this->thread(['replies_count' => 12, 'last_message_id' => 140]);

        $thread->noteReply($this->message(141));

        $this->assertSame(13, $thread->replies_count);
        $this->assertSame(141, (int) $thread->last_message_id);
    }

    /**
     * A raiz é a mensagem de onde o tópico sai, não uma resposta: `refreshMetadata`
     * a exclui do COUNT, então o caminho incremental também precisa excluir.
     */
    public function test_the_root_message_is_not_a_reply(): void
    {
        $thread = $this->thread(['replies_count' => 3, 'last_message_id' => 130]);

        $thread->noteReply($this->message(100));

        $this->assertSame(3, $thread->replies_count);
        $this->assertSame(130, (int) $thread->last_message_id);
    }

    /**
     * Um tópico criado de uma resposta pode ainda não ter a raiz preenchida. Sem id
     * para comparar, toda mensagem conta, o que está certo: sem raiz, a recontagem
     * também não exclui nada.
     */
    public function test_counts_normally_when_there_is_no_root_yet(): void
    {
        $thread = $this->thread(['original_message_id' => null]);

        $thread->noteReply($this->message(101));

        $this->assertSame(1, $thread->replies_count);
    }

    /**
     * Em alguns drivers o contador volta do banco como string; somar sem cast
     * concatenaria em vez de incrementar.
     */
    public function test_a_string_counter_still_increments(): void
    {
        $thread = $this->thread(['replies_count' => '5']);

        $thread->noteReply($this->message(101));

        $this->assertSame(6, $thread->replies_count);
    }
}
