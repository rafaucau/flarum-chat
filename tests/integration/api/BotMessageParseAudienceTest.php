<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Cache\Repository;
use Ramon\Chat\Message;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Mensagens do bot e de webhooks são lidas por todo o canal, então o texto não
 * pode ser interpretado com mais permissão do que um visitante tem.
 *
 * Com ator nulo o flarum/mentions pula a checagem de visibilidade: uma menção a
 * post de discussão oculta viraria link com título e autor, e uma menção a
 * grupo oculto mostraria o nome do grupo.
 */
class BotMessageParseAudienceTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const ADMIN = 1;
    private const MEMBER = 3;

    /** Montadas em partes para scanners de segredo não lerem a fixture como credencial. */
    private const KEY_BOT = 'test'.'webhook'.'bot'.'00000000000000000000000000000000000';
    private const KEY_ADMIN = 'test'.'webhook'.'adm'.'00000000000000000000000000000000000';

    private const LEAK = '@"x"#p1 @"y"#g5';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('flarum-mentions', 'ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                ['id' => self::MEMBER, 'username' => 'member', 'email' => 'member@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1],
            ],
            'groups' => [
                ['id' => 5, 'name_singular' => 'Ninja Oculto', 'name_plural' => 'Ninjas Ocultos', 'is_hidden' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            // Discussão oculta: só a moderação enxerga o post 1.
            'discussions' => [
                ['id' => 1, 'title' => 'Plano secreto', 'slug' => 'plano-secreto', 'created_at' => $now, 'user_id' => self::ADMIN, 'first_post_id' => 1, 'comment_count' => 1, 'hidden_at' => $now],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => $now, 'user_id' => self::ADMIN, 'type' => 'comment', 'content' => '<t><p>segredo</p></t>'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'Geral', 'slug' => 'geral', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_webhooks' => [
                ['id' => 1, 'name' => 'bot', 'channel_id' => 1, 'key' => self::KEY_BOT, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'admin', 'channel_id' => 1, 'user_id' => self::ADMIN, 'key' => self::KEY_ADMIN, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    private function deliver(string $key, string $text): Message
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        $response = $this->send(
            $this->request('POST', '/api/chat/hooks/'.$key, ['json' => ['text' => $text]])
        );

        $this->assertSame(201, $response->getStatusCode());

        return Message::query()->latest('id')->firstOrFail();
    }

    public function test_bot_delivery_does_not_resolve_hidden_posts_or_groups(): void
    {
        $message = $this->deliver(self::KEY_BOT, self::LEAK);

        $stored = $message->getAttributes()['content'];

        $this->assertStringNotContainsString('POSTMENTION', $stored);
        $this->assertStringNotContainsString('GROUPMENTION', $stored);
        $this->assertStringNotContainsString('Plano secreto', (string) $message->formatContent());
        $this->assertStringNotContainsString('Ninjas Ocultos', (string) $message->formatContent());
    }

    public function test_admin_authored_delivery_is_not_parsed_with_admin_rights(): void
    {
        $message = $this->deliver(self::KEY_ADMIN, self::LEAK);

        $this->assertSame(self::ADMIN, $message->user_id);

        $stored = $message->getAttributes()['content'];

        $this->assertStringNotContainsString('POSTMENTION', $stored);
        $this->assertStringNotContainsString('GROUPMENTION', $stored);
    }

    public function test_bot_message_still_formats_ordinary_markup(): void
    {
        $message = $this->deliver(self::KEY_BOT, 'veja https://flarum.org');

        $this->assertStringContainsString('href="https://flarum.org"', (string) $message->formatContent());
    }
}
