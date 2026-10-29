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
use Pusher\Pusher;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\ChannelWasDeleted;
use Ramon\Chat\Realtime\BroadcastListener;
use Ramon\Chat\Realtime\ChatBroadcaster;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * Quem recebe um push do websocket.
 *
 * Canais diretos e sem tag também checam a permissão: um membro que perdeu
 * `ramon-chat.use` (ou foi suspenso, o que rebaixa os grupos) não pode
 * continuar recebendo mensagens privadas ao vivo.
 *
 * Canal apagado é anunciado, senão os membros ficam com uma linha na barra
 * lateral que responde 404. O público precisa ser calculado antes: depois da
 * exclusão o escopo de visibilidade já exclui o canal.
 *
 * O flarum/realtime não está instalado aqui, então o daemon é um substituto que
 * grava tudo, registrado onde o ChatBroadcaster o procura.
 */
class RealtimeAudienceTest extends TestCase
{
    use ResetsVisibilityScopers;

    private const CHATTER = 3;
    private const REVOKED = 4;
    private const CHAT_GROUP = 100;

    /** O daemon substituto, com cada trigger que recebeu. */
    private ?object $recorder = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'users' => [
                ['id' => self::CHATTER, 'username' => 'chatter', 'email' => 'chatter@machine.local', 'password' => 'x', 'is_email_confirmed' => 1],
                ['id' => self::REVOKED, 'username' => 'revoked', 'email' => 'revoked@machine.local', 'password' => 'x', 'is_email_confirmed' => 1],
            ],
            'groups' => [
                ['id' => self::CHAT_GROUP, 'name_singular' => 'Chatter', 'name_plural' => 'Chatters'],
            ],
            'group_user' => [
                ['user_id' => self::CHATTER, 'group_id' => self::CHAT_GROUP],
            ],
            // O chat liberado a um grupo só: REVOKED tem memberships mas não a
            // permissão, o estado que uma revogação ou suspensão deixa.
            'group_permission' => [
                ['group_id' => self::CHAT_GROUP, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                ['id' => 1, 'type' => 'direct', 'name' => 'dm', 'slug' => 'dm', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'type' => 'category', 'name' => 'Geral', 'slug' => 'geral', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_channel_user' => [
                ['channel_id' => 1, 'user_id' => self::CHATTER, 'created_at' => $now],
                ['channel_id' => 1, 'user_id' => self::REVOKED, 'created_at' => $now],
                ['channel_id' => 2, 'user_id' => self::CHATTER, 'created_at' => $now],
                ['channel_id' => 2, 'user_id' => self::REVOKED, 'created_at' => $now],
            ],
        ]);
    }

    private function bindRecorder(): void
    {
        $recorder = new class('key', 'secret', '1') extends Pusher {
            /** @var array<int, mixed> */
            public array $calls = [];

            public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
            {
                $this->calls[] = ['channels' => (array) $channels, 'event' => $event, 'data' => $data];

                return new \stdClass();
            }
        };

        $this->app()->getContainer()->instance(Pusher::class, $recorder);

        // A instalação libera o chat aos membros por padrão; aqui a permissão é
        // retirada para só CHAT_GROUP tê-la.
        $this->database()->table('group_permission')
            ->where('permission', 'ramon-chat.use')
            ->where('group_id', '!=', self::CHAT_GROUP)
            ->delete();
        $this->recorder = $recorder;
    }

    /** @return array<int, array{channels: string[], event: string, data: mixed}> */
    private function pushes(): array
    {
        return $this->recorder->calls ?? [];
    }

    public function test_a_direct_channel_push_skips_members_without_the_chat(): void
    {
        $this->bindRecorder();

        $this->app()->getContainer()->make(ChatBroadcaster::class)->toChannelMembers(
            Channel::query()->findOrFail(1),
            BroadcastListener::EVENT_MESSAGE,
            ['id' => 1],
            null
        );

        $pushes = $this->pushes();

        $this->assertCount(1, $pushes);
        $this->assertSame(['private-user='.self::CHATTER], $pushes[0]['channels']);
    }

    public function test_a_deleted_channel_is_announced_to_its_members(): void
    {
        $this->bindRecorder();

        $channel = Channel::query()->findOrFail(2);
        $channel->deleted_at = Carbon::now();
        $channel->save();

        $this->app()->getContainer()->make(BroadcastListener::class)->whenChannelDeleted(new ChannelWasDeleted($channel));

        $pushes = $this->pushes();

        $this->assertCount(1, $pushes, 'the deletion reached the websocket');
        $this->assertSame(BroadcastListener::EVENT_CHANNEL, $pushes[0]['event']);
        $this->assertSame(['private-user='.self::CHATTER], $pushes[0]['channels'], 'members who may use the chat, and only them');
        $this->assertSame(['channelId' => 2, 'status' => 'open', 'deleted' => true], $pushes[0]['data']);
    }
}
