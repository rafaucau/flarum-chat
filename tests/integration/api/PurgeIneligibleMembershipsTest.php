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
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;

/**
 * A migration que limpa as memberships que o AutoJoinUsers antigo gravava.
 *
 * Chama o `up` da migration direto: o harness migra no setup, antes de haver
 * estado antigo para achar. Rodar de novo é seguro: ela apaga e reconta, então
 * converge em vez de acumular.
 */
class PurgeIneligibleMembershipsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt de "too-obscure", o mesmo hash que o `normalUser()` traz. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'staffer', 'password' => self::PASSWORD_HASH, 'email' => 'staffer@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'unconfirmed', 'password' => self::PASSWORD_HASH, 'email' => 'unconfirmed@machine.local', 'is_email_confirmed' => 0],
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Staff', 'name_plural' => 'Staff'],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => 100],
                ['user_id' => 4, 'group_id' => 100],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'viewForum'],
                ['group_id' => 100, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                $this->channel(1, 'announcements', true),
                $this->channel(2, 'opt-in', false),
            ],
            'chat_channel_user' => [
                // O que o listener antigo gravava: todo mundo, no canal de auto-join.
                $this->membership(1, 1),
                $this->membership(1, 2),
                $this->membership(1, 3),
                $this->membership(1, 4),

                // Alguém que entrou num canal por vontade própria. Não é este caso, e
                // precisa sobreviver mesmo sem poder abri-lo hoje.
                $this->membership(2, 2),
            ],
        ]);

        // Ligar a extensão dá `ramon-chat.use` a MEMBER, e o harness soma as linhas
        // da fixture em vez de substituir a tabela; um fórum restrito ao grupo 100
        // precisa revogar o padrão como um admin faria. Sem isso toda conta tem a
        // permissão e não há nada inelegível para a migration achar.
        $this->database()->table('group_permission')
            ->where('group_id', Group::MEMBER_ID)
            ->where('permission', 'like', 'ramon-chat.%')
            ->delete();
    }

    private function channel(int $id, string $slug, bool $autoJoin): array
    {
        return [
            'id'         => $id,
            'type'       => 'category',
            'name'       => $slug,
            'slug'       => $slug,
            'status'     => 'open',
            'auto_join'  => $autoJoin,
            // Errado de propósito, para provar que a migration recalcula.
            'user_count' => 99,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    private function membership(int $channelId, int $userId): array
    {
        return [
            'channel_id'   => $channelId,
            'user_id'      => $userId,
            'following'    => 1,
            'muted'        => 0,
            'unread_count' => 5,
            'created_at'   => Carbon::now()->toDateTimeString(),
            'updated_at'   => Carbon::now()->toDateTimeString(),
        ];
    }

    private function purge(): void
    {
        $migration = require __DIR__.'/../../../migrations/2026_08_02_000000_purge_ineligible_auto_join_memberships.php';

        $migration['up']($this->database()->getSchemaBuilder());
    }

    /** @return int[] */
    private function memberIds(int $channelId): array
    {
        return $this->database()->table('chat_channel_user')
            ->where('channel_id', $channelId)
            ->pluck('user_id')
            ->map(static fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    public function test_it_removes_only_the_members_who_cannot_use_the_chat(): void
    {
        $this->purge();

        // 1 é o admin e 3 está no grupo permitido. 2 é membro comum, e 4 está no
        // grupo permitido mas sem e-mail confirmado, o que o deixa só com direitos de
        // visitante.
        $this->assertSame([1, 3], $this->memberIds(1));
    }

    public function test_it_leaves_channels_that_were_not_auto_join_alone(): void
    {
        $this->purge();

        $this->assertSame([2], $this->memberIds(2));
    }

    public function test_it_recomputes_the_denormalised_member_count(): void
    {
        $this->purge();

        $this->assertSame(2, (int) $this->database()->table('chat_channels')->where('id', 1)->value('user_count'));

        // Canal intocado fica com o que tinha: a migration não conserta contagens que
        // não mexeu.
        $this->assertSame(99, (int) $this->database()->table('chat_channels')->where('id', 2)->value('user_count'));
    }

    public function test_running_it_twice_changes_nothing_further(): void
    {
        $this->purge();
        $this->purge();

        $this->assertSame([1, 3], $this->memberIds(1));
        $this->assertSame(2, (int) $this->database()->table('chat_channels')->where('id', 1)->value('user_count'));
    }

    public function test_it_purges_nothing_when_members_hold_the_permission(): void
    {
        $this->database()->table('group_permission')->insert([
            'group_id'   => Group::MEMBER_ID,
            'permission' => 'ramon-chat.use',
        ]);

        $this->purge();

        // 4 continua não confirmado, então sai; os outros agora se qualificam.
        $this->assertSame([1, 2, 3], $this->memberIds(1));
    }
}
