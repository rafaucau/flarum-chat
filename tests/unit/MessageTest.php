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

/**
 * O estado derivado do model de mensagem.
 *
 * Parecem triviais, mas cada um é uma fronteira em que a UI se ramifica. Errar
 * `isRedacted` vaza o texto de uma mensagem removida ou mostra a lápide ao
 * próprio autor.
 */
class MessageTest extends QueryTestCase
{
    protected function message(array $attributes = []): Message
    {
        $message = new Message();

        // setRawAttributes, não fill(): são caminhos de leitura, e passar pelos casts
        // do model é o objetivo do teste.
        $message->setRawAttributes(array_merge([
            'id'         => 1,
            'channel_id' => 1,
            'type'       => Message::TYPE_TEXT,
            'content'    => 'hello',
        ], $attributes), true);

        return $message;
    }

    public function test_a_text_message_is_not_a_system_message(): void
    {
        $this->assertFalse($this->message()->isSystem());
    }

    public function test_a_system_message_is_recognised(): void
    {
        $this->assertTrue($this->message(['type' => Message::TYPE_SYSTEM])->isSystem());
    }

    public function test_deletion_is_decided_by_the_timestamp(): void
    {
        $this->assertFalse($this->message()->isDeleted());
        $this->assertTrue($this->message(['deleted_at' => '2026-01-01 00:00:00'])->isDeleted());
    }

    /*
     * A redação (mensagem apagada com o texto retido) não é testada aqui de
     * propósito. No servidor ela depende de um ator e mora em
     * MessageResource::isRedacted(Message, User), então pertence à suíte de
     * integração. O `isRedacted()` sem argumento é a inferência do model do cliente
     * a partir do payload, outra pergunta, e é TypeScript.
     */

    public function test_editing_is_decided_by_the_timestamp(): void
    {
        $this->assertFalse($this->message()->isEdited());
        $this->assertTrue($this->message(['edited_at' => '2026-01-01 00:00:00'])->isEdited());
    }

    /**
     * Só `pinned_at` decide um pin; `pinned_by_id` é atribuição e vira null quando
     * o usuário é apagado, então não pode ser consultado.
     */
    public function test_pinning_is_decided_by_the_timestamp_alone(): void
    {
        $this->assertFalse($this->message()->isPinned());

        $this->assertTrue(
            $this->message(['pinned_at' => '2026-01-01 00:00:00', 'pinned_by_id' => null])->isPinned(),
            'a pin whose author was deleted is still a pin'
        );

        $this->assertFalse(
            $this->message(['pinned_by_id' => 5])->isPinned(),
            'an attribution without a timestamp is not a pin'
        );
    }

    public function test_pinned_at_is_cast_to_a_date(): void
    {
        $message = $this->message(['pinned_at' => '2026-01-01 12:00:00']);

        $this->assertInstanceOf(Carbon::class, $message->pinned_at);
    }
}
