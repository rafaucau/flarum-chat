<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat;

/**
 * Contador e última mensagem de canal e thread, atualizados no banco.
 *
 * O model é carregado antes da transação do envio. Gravar o valor calculado em
 * PHP faz dois envios simultâneos contarem um só, e o commit mais lento põe a
 * mensagem mais antiga como última. Aqui o contador soma no próprio UPDATE, e o
 * ponteiro só avança se a mensagem for mais nova que a gravada.
 *
 * @mixin \Flarum\Database\AbstractModel
 */
trait CountsMessagesAtomically
{
    protected function countNewMessage(string $counter, Message $message): void
    {
        $id = (int) $message->id;

        $row = static::query()->withoutGlobalScopes()->whereKey($this->getKey());

        (clone $row)->increment($counter);

        (clone $row)
            ->where(fn ($query) => $query->whereNull('last_message_id')->orWhere('last_message_id', '<', $id))
            ->update(['last_message_id' => $id, 'last_message_at' => $message->created_at]);

        // O model fica com o mesmo resultado, sem marcar nada como alterado, para
        // um save() depois não regravar os valores antigos por cima.
        $this->setAttribute($counter, (int) $this->getAttribute($counter) + 1);

        if ($this->getAttribute('last_message_id') === null || (int) $this->getAttribute('last_message_id') < $id) {
            $this->setAttribute('last_message_id', $id);
            $this->setAttribute('last_message_at', $message->created_at);
        }

        $this->syncOriginalAttributes([$counter, 'last_message_id', 'last_message_at', 'updated_at']);
    }
}
