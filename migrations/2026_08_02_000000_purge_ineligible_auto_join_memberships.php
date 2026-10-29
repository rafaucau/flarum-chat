<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Group\Group;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Builder;

/**
 * Remove associações que o antigo AutoJoinUsers criou para quem não pode usar
 * o chat.
 *
 * O listener incluía todo `users` em canais `auto_join` sem checar permissão nem
 * visibilidade, e essas linhas inflavam o badge do cabeçalho. O listener já
 * filtra; isto limpa o que ficou.
 *
 * Só canais `auto_join`, os únicos populados em massa. Remove mesmo quem já saiu:
 * não há histórico a manter num canal que nunca foi acessível.
 *
 * A regra de elegibilidade fica copiada aqui, não compartilhada com o listener:
 * migration é histórico congelado. Espelha `User::permissions()` na época
 * (permissões vêm de grupos, conta não confirmada só tem direitos de guest, admin
 * passa direto).
 *
 * Sem rollback: exigiria inventar o estado de leitura.
 */
return [
    'up' => function (Builder $schema) {
        // O Migrator só entrega o schema builder; a conexão vem dele.
        $db = $schema->getConnection();

        $groupIds = $db->table('group_permission')
            ->where('permission', 'ramon-chat.use')
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Concedido a guests: toda conta tem, não há o que podar.
        if (in_array(Group::GUEST_ID, $groupIds, true)) {
            return;
        }

        $memberGranted = in_array(Group::MEMBER_ID, $groupIds, true);

        $inGroups = function (QueryBuilder $query, array $ids): void {
            $query->whereExists(function (QueryBuilder $sub) use ($ids) {
                $sub->selectRaw('1')
                    ->from('group_user')
                    ->whereColumn('group_user.user_id', 'users.id')
                    ->whereIn('group_user.group_id', $ids);
            });
        };

        $eligible = function (QueryBuilder $query) use ($groupIds, $memberGranted, $inGroups): void {
            $query->select('users.id')
                ->from('users')
                ->where(function (QueryBuilder $query) use ($groupIds, $memberGranted, $inGroups) {
                    // Admin tem tudo implicitamente; `isAdmin()` lê os grupos direto, sem
                    // depender de confirmação.
                    $inGroups($query, [Group::ADMINISTRATOR_ID]);

                    $query->orWhere(function (QueryBuilder $query) use ($groupIds, $memberGranted, $inGroups) {
                        // A confirmação é o que inclui MEMBER e os grupos da conta nas permissões.
                        $query->where('users.is_email_confirmed', true);

                        // Concedido a membros, que toda conta confirmada é implicitamente.
                        if (! $memberGranted) {
                            $inGroups($query, $groupIds);
                        }
                    });
                });
        };

        $channelIds = $db->table('chat_channels')
            ->where('auto_join', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($channelIds === []) {
            return;
        }

        $db->table('chat_channel_user')
            ->whereIn('channel_id', $channelIds)
            ->whereNotIn('user_id', $eligible)
            ->delete();

        // `user_count` é desnormalizado (ver Channel::refreshMetadata()). Recalculado
        // por canal, não num UPDATE correlacionado: são poucos e fica portável entre bancos.
        foreach ($channelIds as $channelId) {
            $db->table('chat_channels')
                ->where('id', $channelId)
                ->update([
                    'user_count' => $db->table('chat_channel_user')
                        ->where('channel_id', $channelId)
                        ->whereNull('left_at')
                        ->count(),
                ]);
        }
    },

    'down' => fn () => null,
];
