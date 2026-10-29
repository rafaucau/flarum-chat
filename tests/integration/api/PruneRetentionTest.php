<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;
use Ramon\Chat\Console\PruneChatCommand;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * O que a retenção noturna (`chat:prune`, 90 dias por padrão) apaga.
 *
 * Fixadas, raízes de tópicos ainda vivos e mensagens com denúncia aberta ficam.
 * Tópicos e contadores de não lidas não podem apontar para o que sumiu.
 */
class PruneRetentionTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const READER = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $old = Carbon::now()->subDays(200)->toDateTimeString();
        $now = Carbon::now()->toDateTimeString();
        $message = fn (int $id, int $number, string $at, array $extra = []) => array_merge([
            'id' => $id, 'channel_id' => 1, 'user_id' => 1, 'number' => $number, 'type' => 'text',
            'content' => '<t>m'.$id.'</t>', 'created_at' => $at, 'updated_at' => $at,
        ], $extra);

        $this->prepareDatabase([
            'users' => [
                ['id' => self::READER, 'username' => 'reader', 'email' => 'reader@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'geral', 'slug' => 'geral', 'status' => 'open', 'messages_count' => 6, 'created_at' => $old, 'updated_at' => $old],
            ],
            'chat_channel_user' => [
                // Nunca leu nada: as seis mensagens contam como não lidas.
                ['channel_id' => 1, 'user_id' => self::READER, 'joined_at' => $old, 'last_read_message_id' => 0, 'unread_count' => 6, 'created_at' => $old, 'updated_at' => $old],
            ],
            'chat_messages' => [
                $message(1, 1, $old),
                $message(2, 2, $old, ['pinned_at' => $old, 'pinned_by_id' => 1]),
                $message(3, 3, $old),
                $message(4, 4, $old, ['thread_id' => 1]),
                $message(5, 5, $now, ['thread_id' => 1]),
                $message(6, 6, $old, ['thread_id' => 2]),
            ],
            'chat_threads' => [
                // Tópico 1: raiz antiga (4) com resposta de hoje (5). Tópico 2:
                // só a raiz antiga (6), nada recente.
                ['id' => 1, 'channel_id' => 1, 'original_message_id' => 4, 'creator_id' => 1, 'status' => 'open', 'replies_count' => 1, 'last_message_id' => 5, 'last_message_at' => $now, 'created_at' => $old, 'updated_at' => $now],
                ['id' => 2, 'channel_id' => 1, 'original_message_id' => 6, 'creator_id' => 1, 'status' => 'open', 'replies_count' => 0, 'last_message_id' => 6, 'last_message_at' => $old, 'created_at' => $old, 'updated_at' => $old],
            ],
            'chat_message_flags' => [
                ['id' => 1, 'message_id' => 3, 'user_id' => self::READER, 'reason' => 'spam', 'created_at' => $old],
            ],
        ]);
    }

    private function prune(): void
    {
        $this->app()->getContainer()->make(PruneChatCommand::class)->run(new ArrayInput([]), new BufferedOutput());
    }

    /** @return int[] */
    private function remaining(): array
    {
        return $this->database()->table('chat_messages')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_it_keeps_what_is_still_in_use(): void
    {
        $this->prune();

        // Some 1 (comum) e 6 (raiz de tópico parado); ficam a fixada (2), a
        // denunciada (3) e a raiz do tópico vivo (4), além da recente (5).
        $this->assertSame([2, 3, 4, 5], $this->remaining());
        $this->assertSame(1, $this->database()->table('chat_message_flags')->count());
    }

    public function test_counters_are_recounted(): void
    {
        $this->prune();

        $this->assertSame(4, (int) $this->database()->table('chat_channel_user')->where('user_id', self::READER)->value('unread_count'));
        $this->assertNull($this->database()->table('chat_threads')->where('id', 2)->value('last_message_id'));
    }
}
