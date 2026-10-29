<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Filter;

use Ramon\Chat\Message;
use Ramon\Chat\Search\Filter\MessagePinnedFilter;
use Ramon\Chat\Tests\unit\QueryTestCase;

class MessagePinnedFilterTest extends QueryTestCase
{
    public function test_filter_key_is_pinned(): void
    {
        $this->assertSame('pinned', (new MessagePinnedFilter())->getFilterKey());
    }

    /**
     * `pinned_at IS NOT NULL` é a única fonte de verdade de um pin. Ler
     * `pinned_by_id` perderia pins cujo autor foi apagado, já que a coluna é
     * ON DELETE SET NULL.
     */
    public function test_truthy_value_keeps_only_pinned_messages(): void
    {
        $query = Message::query();

        (new MessagePinnedFilter())->filter($this->state($query), '1', false);

        $this->assertStringContainsString('"pinned_at" is not null', $this->sql($query));
    }

    public function test_falsy_value_excludes_pinned_messages(): void
    {
        $query = Message::query();

        (new MessagePinnedFilter())->filter($this->state($query), '0', false);

        $this->assertStringContainsString('"pinned_at" is null', $this->sql($query));
    }

    /**
     * A negação compõe com o valor em vez de sobrescrevê-lo, para
     * `filter[-pinned]=1` e `filter[pinned]=0` significarem o mesmo.
     */
    public function test_negation_inverts_the_predicate(): void
    {
        $query = Message::query();

        (new MessagePinnedFilter())->filter($this->state($query), '1', true);

        $this->assertStringContainsString('"pinned_at" is null', $this->sql($query));
    }

    public function test_double_negation_keeps_pinned_messages(): void
    {
        $query = Message::query();

        (new MessagePinnedFilter())->filter($this->state($query), '0', true);

        $this->assertStringContainsString('"pinned_at" is not null', $this->sql($query));
    }

    /**
     * Valor ilegível deixa a query como está. Um filtro que caísse em "só fixadas"
     * esconderia o resto do canal.
     */
    public function test_unparseable_value_adds_no_predicate(): void
    {
        $query = Message::query();
        $before = $this->sql($query);

        (new MessagePinnedFilter())->filter($this->state($query), 'maybe', false);

        $this->assertSame($before, $this->sql($query));
    }

    public function test_array_value_uses_its_first_entry(): void
    {
        $query = Message::query();

        (new MessagePinnedFilter())->filter($this->state($query), ['1', '0'], false);

        $this->assertStringContainsString('"pinned_at" is not null', $this->sql($query));
    }
}
