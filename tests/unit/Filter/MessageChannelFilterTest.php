<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Filter;

use Ramon\Chat\Message;
use Ramon\Chat\Search\Filter\MessageChannelFilter;
use Ramon\Chat\Tests\unit\QueryTestCase;

/**
 * O filtro de canal decide o que o stream principal mostra. A cláusula "manter
 * as raízes de tópico mas tirar as respostas" é a mais fácil de quebrar, e o
 * cliente conta com ela ao recusar anexar uma resposta de tópico em tempo real
 * à janela do canal.
 */
class MessageChannelFilterTest extends QueryTestCase
{
    public function test_filter_key_is_channel(): void
    {
        $this->assertSame('channel', (new MessageChannelFilter())->getFilterKey());
    }

    public function test_it_scopes_to_the_channel(): void
    {
        $query = Message::query();

        (new MessageChannelFilter())->filter($this->state($query), '7', false);

        $sql = $this->sql($query);

        $this->assertStringContainsString('"chat_messages"."channel_id" =', $sql);
        $this->assertContains(7, $query->getBindings());
    }

    /**
     * Respostas de tópico ficam fora por padrão: o stream mostra o tópico como uma
     * raiz com indicador, não cada resposta.
     */
    public function test_thread_replies_are_excluded_by_default(): void
    {
        $query = Message::query();

        (new MessageChannelFilter())->filter($this->state($query), '7', false);

        $sql = $this->sql($query);

        $this->assertStringContainsString('"chat_messages"."thread_id" is null', $sql);
        // A raiz fica por um exists correlacionado em chat_threads.
        $this->assertStringContainsString('exists', $sql);
        $this->assertStringContainsString('chat_threads', $sql);
    }

    /**
     * Exige id de canal diferente de zero; zero ou valor não numérico não pode
     * virar `channel_id = 0`, que não retornaria nada sem aviso.
     */
    public function test_zero_channel_adds_no_predicate(): void
    {
        $query = Message::query();
        $before = $this->sql($query);

        (new MessageChannelFilter())->filter($this->state($query), '0', false);

        $this->assertSame($before, $this->sql($query));
    }

    /**
     * Negado, o filtro quer dizer "fora deste canal" e a cláusula das respostas não
     * se aplica: tirar respostas de *outros* canais não foi o pedido.
     */
    public function test_negated_filter_skips_the_thread_clause(): void
    {
        $query = Message::query();

        (new MessageChannelFilter())->filter($this->state($query), '7', true);

        $sql = $this->sql($query);

        $this->assertStringContainsString('"chat_messages"."channel_id" !=', $sql);
        $this->assertStringNotContainsString('chat_threads', $sql);
    }
}
