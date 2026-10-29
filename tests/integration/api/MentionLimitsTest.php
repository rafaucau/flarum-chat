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
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Quantas pessoas uma mensagem notifica ao nomeá-las.
 *
 * Sem teto, uma mensagem num canal aberto mandaria e-mail para centenas de
 * contas, com uma consulta por `@nome` no fallback. Como no Discourse: acima de
 * 10 pessoas, nenhuma menção direta notifica.
 */
class MentionLimitsTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    private const AUTHOR = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();
        $users = [['id' => self::AUTHOR, 'username' => 'author', 'email' => 'author@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1]];

        for ($id = 100; $id < 115; $id++) {
            $users[] = ['id' => $id, 'username' => 'u'.$id, 'email' => 'u'.$id.'@machine.local', 'password' => self::PASSWORD_HASH, 'is_email_confirmed' => 1];
        }

        $this->prepareDatabase([
            'users' => $users,
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'geral', 'slug' => 'geral', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => self::AUTHOR, 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    private function say(string $content): int
    {
        $this->app()->getContainer()->make(Repository::class)->clear();

        $response = $this->send($this->request('POST', '/api/chat-messages', [
            'authenticatedAs' => self::AUTHOR,
            'json'            => ['data' => ['type' => 'chat-messages', 'attributes' => ['channelId' => 1, 'content' => $content]]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }

    private function userMentions(int $messageId): int
    {
        return $this->database()->table('chat_message_mentions')->where('message_id', $messageId)->where('type', 'user')->count();
    }

    public function test_a_few_mentions_still_notify(): void
    {
        $this->assertSame(3, $this->userMentions($this->say('@u100 @u101 @u102 olá')));
    }

    public function test_more_than_the_cap_notifies_nobody_directly(): void
    {
        $names = implode(' ', array_map(fn ($id) => '@u'.$id, range(100, 110)));

        $this->assertSame(0, $this->userMentions($this->say($names)));
    }

    public function test_a_repeated_name_is_looked_up_once(): void
    {
        $lookups = 0;

        $this->database()->listen(function ($query) use (&$lookups) {
            if (str_contains($query->sql, 'from "users" where "username"') || str_contains($query->sql, 'from `users` where `username`')) {
                $lookups++;
            }
        });

        $this->say(str_repeat('@zz ', 300));

        $this->assertLessThanOrEqual(1, $lookups);
    }
}
