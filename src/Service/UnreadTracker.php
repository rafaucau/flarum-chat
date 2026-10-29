<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Message;
use Ramon\Chat\MessageMention;
use Ramon\Chat\Thread;
use Ramon\Chat\ThreadUser;

/**
 * Mantém os contadores desnormalizados de não lidas nas memberships.
 *
 * Contadores, não contagem a partir de last_read_message_id: a barra lateral
 * desenha todos os canais seguidos a cada render.
 *
 * Canais para todos são a exceção: podem ter centenas de milhares de membros e
 * são poucos, então contar na leitura é mais barato que um UPDATE na sala
 * inteira por mensagem. Nas linhas deles `unread_count` fica zero e
 * unreadFor() conta a partir do marcador.
 */
class UnreadTracker
{
    /**
     * Máximo contado como não lido num canal para todos. O badge mostra "99+"
     * bem antes; o teto evita contar um ano de ausência.
     */
    public const COUNTED_UNREAD_CAP = 1000;

    /**
     * Incrementa as não lidas de todos menos o autor, e as menções de quem a
     * mensagem menciona de fato.
     */
    public function recordNewMessage(Message $message): void
    {
        // Instruções separadas, como em unreadOf(): os where* são tipados como query
        // builder e perderiam o Eloquent que narrowToMentioned() recebe.
        $base = ChannelUser::query();
        $base->where('channel_id', $message->channel_id);
        $base->whereNull('left_at');

        if ($message->user_id !== null) {
            $base->where('user_id', '!=', $message->user_id);
        }

        // Memberships silenciadas não ganham badge: é a diferença entre silenciar e nível 0.
        if ($this->keepsCounters((int) $message->channel_id)) {
            (clone $base)
                ->where('muted', false)
                ->increment('unread_count');
        }

        $mentioned = $this->narrowToMentioned(clone $base, $message, (int) $message->channel_id);

        if ($mentioned !== null) {
            $mentioned->increment('unread_mentions_count');
        }

        if ($message->thread_id !== null) {
            ThreadUser::query()
                ->where('thread_id', $message->thread_id)
                ->when(
                    $message->user_id !== null,
                    fn ($q) => $q->where('user_id', '!=', $message->user_id)
                )
                ->increment('unread_count');
        }
    }

    /**
     * Recalcula os contadores a partir da origem. Usado ao apagar ou mover, onde
     * decrementar às cegas poderia ficar negativo.
     */
    public function recalculate(ChannelUser $membership): ChannelUser
    {
        $lastRead = $membership->last_read_message_id ?? 0;

        // `user_id != x` descartaria mensagens de sistema (user_id NULL) e contaria
        // menos do que recordNewMessage() incrementou.
        $unreadQuery = Message::query()
            ->where('channel_id', $membership->channel_id)
            ->where('id', '>', $lastRead)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($membership) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', '!=', $membership->user_id);
            });

        $membership->unread_count = (clone $unreadQuery)->count();

        $membership->unread_mentions_count = (clone $unreadQuery)
            ->whereExists(function ($sub) use ($membership) {
                $sub->selectRaw(1)
                    ->from('chat_message_mentions')
                    ->whereColumn('chat_message_mentions.message_id', 'chat_messages.id')
                    ->where(function ($q) use ($membership) {
                        $q->where(function ($q) use ($membership) {
                            $q->where('chat_message_mentions.type', MessageMention::TYPE_USER)
                                ->where('chat_message_mentions.user_id', $membership->user_id);
                        })->orWhereIn('chat_message_mentions.type', [
                            MessageMention::TYPE_HERE,
                            MessageMention::TYPE_ALL,
                        ]);
                    });
            })
            ->count();

        return $membership;
    }

    /**
     * Tira uma mensagem dos contadores do canal, ao apagar ou mover para fora.
     *
     * Só quem não leu além dela a contava, então só essas linhas mudam, em um, com
     * dois UPDATEs qualquer que seja o tamanho do canal. Os `> 0` evitam negativo
     * quando um mark-read concorre com a exclusão. `$channelId` vem à parte porque a
     * coluna da mensagem movida já aponta o destino.
     */
    public function forgetMessage(Message $message, int $channelId): void
    {
        if ($message->isDeleted() && ! $message->wasChanged('deleted_at')) {
            return;
        }

        if ($this->keepsCounters($channelId)) {
            $this->unreadOf($message, $channelId)
                ->where('muted', false)
                ->where('unread_count', '>', 0)
                ->decrement('unread_count');
        }

        $mentioned = $this->narrowToMentioned($this->unreadOf($message, $channelId), $message, $channelId);

        if ($mentioned !== null) {
            $mentioned
                ->where('unread_mentions_count', '>', 0)
                ->decrement('unread_mentions_count');
        }
    }

    /**
     * O inverso de forgetMessage(), para mensagem movida para um canal: a regra de
     * recordNewMessage(), restrita a quem tem marcador abaixo dela (a mensagem
     * mantém o id).
     */
    public function addMovedMessage(Message $message, int $channelId): void
    {
        if ($message->isDeleted()) {
            return;
        }

        if ($this->keepsCounters($channelId)) {
            $this->unreadOf($message, $channelId)
                ->where('muted', false)
                ->increment('unread_count');
        }

        $mentioned = $this->narrowToMentioned($this->unreadOf($message, $channelId), $message, $channelId);

        if ($mentioned !== null) {
            $mentioned->increment('unread_mentions_count');
        }
    }

    /**
     * Restringe uma consulta de memberships aos membros que a mensagem menciona,
     * ou null se não menciona ninguém.
     *
     * Menção ao canal inteiro não restringe nada: listar os ids poria um placeholder
     * por membro no UPDATE, que o banco recusa acima de 65.535. Menções nominais
     * entram como inteiros literais pelo mesmo motivo.
     *
     * @param  Builder<ChannelUser>  $members
     * @return Builder<ChannelUser>|null
     */
    protected function narrowToMentioned(Builder $members, Message $message, int $channelId): ?Builder
    {
        if ($message->mentions->contains(fn (MessageMention $mention) => $mention->isChannelWide())) {
            return $members;
        }

        $ids = $this->mentionedUserIds($message, $channelId);

        if ($ids === []) {
            return null;
        }

        $members->getQuery()->whereIntegerInRaw('user_id', $ids);

        return $members;
    }

    /**
     * Se as linhas do canal têm contador. Canais para todos não têm (ver o
     * docblock da classe).
     */
    protected function keepsCounters(int $channelId): bool
    {
        $channel = Channel::query()->find($channelId);

        return $channel === null || ! $channel->isForEveryone();
    }

    /**
     * As não lidas de uma membership: o contador ou, num canal para todos, as
     * mensagens após o marcador, contadas na hora.
     */
    public function unreadFor(Channel $channel, ?ChannelUser $membership): int
    {
        if ($membership === null) {
            return 0;
        }

        if (! $channel->isForEveryone()) {
            return (int) $membership->unread_count;
        }

        return $membership->muted ? 0 : $this->countUnread($membership);
    }

    /**
     * Mensagens após o marcador que não são do membro, até COUNTED_UNREAD_CAP. A
     * mesma regra de recordNewMessage(): sistema conta, apagadas não.
     */
    protected function countUnread(ChannelUser $membership): int
    {
        $unread = Message::query()
            ->where('channel_id', $membership->channel_id)
            ->where('id', '>', $membership->last_read_message_id ?? 0)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($membership) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', '!=', $membership->user_id);
            })
            ->select('id')
            ->limit(self::COUNTED_UNREAD_CAP);

        return (int) Message::query()->getQuery()->newQuery()->fromSub($unread, 'unread')->count();
    }

    /**
     * Memberships ativas que não leram até esta mensagem e não são do autor:
     * exatamente as linhas cujo contador a inclui.
     *
     * @return Builder<ChannelUser>
     */
    protected function unreadOf(Message $message, int $channelId): Builder
    {
        $query = ChannelUser::query();

        $query->where('channel_id', $channelId);
        $query->whereNull('left_at');
        $query->where(function ($q) use ($message) {
            $q->whereNull('last_read_message_id')
                ->orWhere('last_read_message_id', '<', $message->id);
        });

        if ($message->user_id !== null) {
            $query->where('user_id', '!=', $message->user_id);
        }

        return $query;
    }

    /**
     * Marca o canal como lido até uma mensagem e devolve a membership. Null marca
     * até a última mensagem do canal.
     */
    public function markChannelRead(Channel $channel, User $actor, ?int $upToMessageId = null): ?ChannelUser
    {
        $membership = $channel->membershipFor($actor);

        if ($membership === null) {
            return null;
        }

        $target = $upToMessageId ?? $channel->last_message_id;

        // O marcador nunca volta: cliente desatualizado com id antigo não pode
        // ressuscitar mensagens já lidas.
        if ($target !== null && ($membership->last_read_message_id ?? 0) > $target) {
            return $membership;
        }

        $membership->markReadUpTo($target);
        $membership->save();

        return $membership;
    }

    public function markThreadRead(Thread $thread, User $actor, ?int $upToMessageId = null): ?ThreadUser
    {
        $membership = $thread->membershipFor($actor);

        if ($membership === null) {
            return null;
        }

        $target = $upToMessageId ?? $thread->last_message_id;

        if ($target !== null && ($membership->last_read_message_id ?? 0) > $target) {
            return $membership;
        }

        $membership->markReadUpTo($target);
        $membership->save();

        return $membership;
    }

    /**
     * Os usuários que a mensagem notifica por nome, expandindo menções de grupo e
     * de canal inteiro para os membros do canal.
     *
     * @return int[]
     */
    public function mentionedUserIds(Message $message, ?int $channelId = null): array
    {
        $channelId ??= (int) $message->channel_id;

        $mentions = $message->mentions;

        if ($mentions->isEmpty()) {
            return [];
        }

        $userIds = $mentions
            ->where('type', MessageMention::TYPE_USER)
            ->pluck('user_id')
            ->filter()
            ->all();

        $groupIds = $mentions
            ->where('type', MessageMention::TYPE_GROUP)
            ->pluck('group_id')
            ->filter()
            ->all();

        // Menção de grupo alcança os membros do grupo neste canal, não no fórum
        // inteiro. O grupo é checado de novo aqui para linhas gravadas antes das
        // exclusões (grupos ocultos, convidados, membros) não expandirem.
        if ($groupIds !== []) {
            $groupIds = Group::query()
                ->whereKey($groupIds)
                ->where('is_hidden', false)
                ->whereNotIn('id', [Group::GUEST_ID, Group::MEMBER_ID])
                ->pluck('id')
                ->all();
        }

        if ($groupIds !== []) {
            $groupMemberIds = ChannelUser::query()
                ->where('channel_id', $channelId)
                ->whereNull('left_at')
                ->whereIn('user_id', fn ($sub) => $sub->select('user_id')->from('group_user')->whereIn('group_id', $groupIds))
                ->pluck('user_id')
                ->all();

            $userIds = array_merge($userIds, $groupMemberIds);
        }

        $channelWide = $mentions->first(fn (MessageMention $m) => $m->isChannelWide()) !== null;

        if ($channelWide) {
            $memberIds = ChannelUser::query()
                ->where('channel_id', $channelId)
                ->whereNull('left_at')
                ->pluck('user_id')
                ->all();

            $userIds = array_merge($userIds, $memberIds);
        }

        // O autor nunca notifica a si mesmo.
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        return array_values(array_filter(
            $userIds,
            fn (int $id) => $id !== (int) $message->user_id
        ));
    }

    /**
     * As memberships ativas do ator, restritas a canais que ele ainda vê.
     *
     * Toda soma abaixo parte daqui: a membership sobrevive ao acesso que a criou
     * (permissão revogada, tag restrita) e manteria um `unread_count` de canal que
     * o ator não abre. Mesma ideia do `messageCount` do flarum/messages via
     * `whereVisibleTo`. O scope também falha fechado em `ramon-chat.use`.
     *
     * @return Builder<ChannelUser>
     */
    protected function visibleMemberships(User $user): Builder
    {
        $query = ChannelUser::query();

        // Instruções separadas: os where* são tipados como query builder e perderiam
        // o Eloquent que os chamadores continuam restringindo.
        $query->where('user_id', $user->id);
        $query->whereNull('left_at');
        $query->whereIn('channel_id', Channel::whereVisibleTo($user)->select('chat_channels.id'));

        return $query;
    }

    /**
     * As memberships visíveis com contador, para somar. Fora os canais para todos,
     * cujas linhas podem ter contagem antiga.
     *
     * @return Builder<ChannelUser>
     */
    protected function counterMemberships(User $user): Builder
    {
        $query = $this->visibleMemberships($user);

        $query->getQuery()->whereNotIn('channel_id', Channel::idsForEveryone());

        return $query;
    }

    /**
     * Não lidas do usuário em cada canal para todos que segue, por id do canal.
     * Uma contagem limitada por canal, e são poucos.
     *
     * @return array<int, int>
     */
    protected function countedUnread(User $user): array
    {
        $query = $this->visibleMemberships($user);

        $query->getQuery()->whereIn('channel_id', Channel::idsForEveryone());
        $query->where('following', true);
        $query->where('muted', false);

        $counts = [];

        foreach ($query->get() as $membership) {
            $counts[(int) $membership->channel_id] = $this->countUnread($membership);
        }

        return $counts;
    }

    /**
     * Total de canais com não lidas, para o badge do cabeçalho.
     */
    public function totalUnreadFor(User $user): int
    {
        if (! $user->exists) {
            return 0;
        }

        $counted = array_filter($this->countedUnread($user));

        return (int) $this->counterMemberships($user)
            ->where('following', true)
            ->where('muted', false)
            ->where('unread_count', '>', 0)
            ->count() + count($counted);
    }

    /**
     * Quantas mensagens não lidas o usuário tem em todos os canais.
     *
     * Diferente de totalUnreadFor(), que conta canais. O badge quer as mensagens:
     * "3 canais" parece bem menos que 40 mensagens esperando. Canais silenciados
     * ficam fora, como no badge.
     */
    public function totalUnreadMessagesFor(User $user): int
    {
        if (! $user->exists) {
            return 0;
        }

        return (int) $this->counterMemberships($user)
            ->where('following', true)
            ->where('muted', false)
            ->sum('unread_count') + array_sum($this->countedUnread($user));
    }

    public function totalUnreadMentionsFor(User $user): int
    {
        if (! $user->exists) {
            return 0;
        }

        return (int) $this->visibleMemberships($user)->sum('unread_mentions_count');
    }
}
