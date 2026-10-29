<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Access;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Mockery;
use Ramon\Chat\Tests\unit\QueryTestCase;
use Ramon\Chat\Access\MessagePolicy;
use Ramon\Chat\Access\VisibilityCache;
use Ramon\Chat\Channel;
use Ramon\Chat\Message;
use Ramon\Chat\Service\ChannelOwnership;

/**
 * As regras de fixar e de abrir tópico.
 *
 * As duas devolvem `false` explícito em vez de se abster: o Gate do Flarum só
 * aplica o fallback de isAdmin() quando *nenhuma* policy decide, e esse fallback
 * testa o nome da ability, não o da permissão.
 */
class MessagePolicyTest extends QueryTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    protected function policy(): MessagePolicy
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturn(0);

        // Cache de verdade, não mock: é um memo sem colaboradores, e um mock teria de
        // receber a resposta de cada `remember()`, justamente o que está em teste nos
        // ramos que chegam à visibilidade.
        return new MessagePolicy($settings, new VisibilityCache(), new ChannelOwnership($settings));
    }

    protected function channel(bool $threading = true): Channel
    {
        $channel = new Channel();
        $channel->setRawAttributes([
            'id'                => 1,
            'type'              => 'category',
            'status'            => 'open',
            'threading_enabled' => $threading,
        ], true);

        return $channel;
    }

    protected function message(array $attributes = [], bool $threading = true): Message
    {
        $message = new Message();
        $message->setRawAttributes(array_merge([
            'id'         => 10,
            'channel_id' => 1,
            'type'       => Message::TYPE_TEXT,
            'content'    => 'hello',
        ], $attributes), true);

        $message->setRelation('channel', $this->channel($threading));

        return $message;
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    protected function actor(array $permissions = [], bool $canPost = true): User
    {
        $user = Mockery::mock(User::class)->makePartial();

        $user->shouldReceive('hasPermission')->andReturnUsing(
            fn (string $permission) => $permissions[$permission] ?? false
        );

        $user->shouldReceive('can')->andReturnUsing(
            fn (string $ability) => $ability === 'postMessage' ? $canPost : ($permissions[$ability] ?? false)
        );

        return $user;
    }

    // ── createThread ──

    public function test_thread_can_be_started_with_the_permission(): void
    {
        $this->assertTrue(
            $this->policy()->createThread(
                $this->actor(['ramon-chat.createThread' => true]),
                $this->message()
            )
        );
    }

    public function test_thread_cannot_be_started_without_the_permission(): void
    {
        $this->assertFalse(
            $this->policy()->createThread($this->actor(), $this->message())
        );
    }

    /**
     * Sem a permissão a pessoa ainda responde (um postMessage comum); só não pode
     * *iniciar* um tópico.
     */
    public function test_lacking_the_permission_does_not_affect_replying(): void
    {
        $actor = $this->actor();

        $this->assertFalse($this->policy()->createThread($actor, $this->message()));
        $this->assertTrue($this->policy()->reply($actor, $this->message()));
    }

    public function test_thread_cannot_be_started_where_threading_is_off(): void
    {
        $this->assertFalse(
            $this->policy()->createThread(
                $this->actor(['ramon-chat.createThread' => true]),
                $this->message(threading: false)
            )
        );
    }

    /** Tópicos têm um nível só. */
    public function test_a_message_already_in_a_thread_cannot_be_branched(): void
    {
        $this->assertFalse(
            $this->policy()->createThread(
                $this->actor(['ramon-chat.createThread' => true]),
                $this->message(['thread_id' => 3])
            )
        );
    }

    public function test_a_deleted_message_cannot_be_branched(): void
    {
        $this->assertFalse(
            $this->policy()->createThread(
                $this->actor(['ramon-chat.createThread' => true]),
                $this->message(['deleted_at' => '2026-01-01 00:00:00'])
            )
        );
    }

    // ── pin ──

    public function test_pinning_requires_its_own_permission(): void
    {
        $this->assertTrue(
            $this->policy()->pin($this->actor(['ramon-chat.pinMessage' => true]), $this->message())
        );

        $this->assertFalse(
            $this->policy()->pin($this->actor(), $this->message())
        );
    }

    /**
     * Fixar não está dentro de `moderate` de propósito: moderar remove e move
     * mensagens alheias, fixar decide o que o canal vê primeiro.
     */
    public function test_moderate_alone_does_not_grant_pinning(): void
    {
        $this->assertFalse(
            $this->policy()->pin($this->actor(['ramon-chat.moderate' => true]), $this->message())
        );
    }

    public function test_system_and_deleted_messages_cannot_be_pinned(): void
    {
        $actor = $this->actor(['ramon-chat.pinMessage' => true]);

        $this->assertFalse($this->policy()->pin($actor, $this->message(['type' => Message::TYPE_SYSTEM])));
        $this->assertFalse($this->policy()->pin($actor, $this->message(['deleted_at' => '2026-01-01 00:00:00'])));
    }

    /**
     * Resposta de tópico pode ser fixada. Ela não aparece na janela do canal, mas a
     * lista de fixadas pede `includeThreadReplies`, então o pin continua alcançável.
     */
    public function test_a_message_in_a_thread_can_be_pinned(): void
    {
        $this->assertTrue(
            $this->policy()->pin(
                $this->actor(['ramon-chat.pinMessage' => true]),
                $this->message(['thread_id' => 3])
            )
        );
    }
}
