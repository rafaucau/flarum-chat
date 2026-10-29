<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Carbon\Carbon;
use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\ChannelModeratorChanged;
use Ramon\Chat\Event\ChannelOwnershipTransferred;
use Ramon\Chat\Event\ChannelRanksChanged;
use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Event\ChannelWasArchived;
use Ramon\Chat\Event\ChannelWasCreated;
use Ramon\Chat\Event\ChannelWasDeleted;
use Ramon\Chat\Event\ChannelWasEdited;
use Ramon\Chat\Event\FlagsChanged;
use Ramon\Chat\Event\InviteWasCancelled;
use Ramon\Chat\Event\InviteWasDeclined;
use Ramon\Chat\Event\MessagePinToggled;
use Ramon\Chat\Event\MessagesWereMoved;
use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Event\MessageWasEdited;
use Ramon\Chat\Event\MessageWasPurged;
use Ramon\Chat\Event\MessageWasRestored;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Event\OwnershipTransferEnded;
use Ramon\Chat\Event\OwnershipTransferRequested;
use Ramon\Chat\Event\ReactionToggled;
use Ramon\Chat\Event\ThreadWasCreated;
use Ramon\Chat\Event\ThreadWasEdited;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Event\UserWasInvited;
use Ramon\Chat\ChannelRankUser;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Message;
use Ramon\Chat\MessageFlag;
use Ramon\Chat\Service\ChannelRanks;
use Ramon\Chat\Thread;
use Ramon\Chat\Upload;

/**
 * Traduz eventos de domínio em payloads de websocket. Só é registrado com
 * flarum/realtime ligado, mas tudo é null-safe para uma instalação incompleta cair
 * no polling em vez de dar erro.
 */
class BroadcastListener
{
    /**
     * Nomes que o cliente escuta. O prefixo evita colisão com eventos do core ou de
     * outras extensões no mesmo canal privado.
     */
    public const EVENT_MESSAGE = 'ramonChat.message';
    public const EVENT_MESSAGE_CHANGED = 'ramonChat.messageChanged';

    /**
     * Separado de `messageChanged`: não há linha para redesenhar, o cliente a remove.
     */
    public const EVENT_MESSAGE_PURGED = 'ramonChat.messagePurged';

    public const EVENT_REACTION = 'ramonChat.reaction';
    public const EVENT_THREAD = 'ramonChat.thread';
    public const EVENT_CHANNEL = 'ramonChat.channel';

    /**
     * Entradas, convites e saídas. Vai para a pessoa envolvida (as outras abas dela
     * acompanham) e para os membros quando a sala enxerga a mudança.
     */
    public const EVENT_MEMBERSHIP = 'ramonChat.membership';

    /**
     * Mensagens movidas entre canais. Só ids: a origem remove as linhas, o destino
     * as lê pela API, que decide o que cada leitor pode ver.
     */
    public const EVENT_MESSAGES_MOVED = 'ramonChat.messagesMoved';

    /**
     * A fila de moderação mudou. Ver FlagsChanged.
     */
    public const EVENT_FLAGS = 'ramonChat.flags';

    /**
     * Os cargos do canal mudaram. Leva a lista inteira (pequena, legível por todo
     * membro e igual para todos), para o cliente redesenhar sem fetch.
     */
    public const EVENT_RANKS = 'ramonChat.ranks';

    /**
     * A partir de quantos membros o push vai para a fila, seja qual for a
     * configuração. Só com worker contínuo; em `sync` e `database` roda inline
     * (QueueKind).
     */
    public const LARGE_AUDIENCE = 500;

    public function __construct(
        protected ChatBroadcaster $broadcaster,
        protected LoggerInterface $log,
        protected ChannelRanks $ranks
    ) {
    }

    public function whenMessageSent(MessageWasSent $event): void
    {
        $channel = $event->message->channel;

        if ($channel === null) {
            return;
        }

        $this->broadcaster->toChannelMembers(
            $channel,
            self::EVENT_MESSAGE,
            $this->messagePayload($event->message),
            // O autor também: as outras abas e aparelhos dele assinam o mesmo canal
            // privado. A aba que enviou ignora o eco (mesmo id, sem som nem não lida).
            null
        );
    }

    public function whenMessageChanged(MessageWasEdited|MessageWasDeleted|MessageWasRestored|MessagePinToggled $event): void
    {
        $channel = $event->message->channel;

        if ($channel === null) {
            return;
        }

        $this->broadcaster->toChannelMembers(
            $channel,
            self::EVENT_MESSAGE_CHANGED,
            $this->messagePayload($event->message),
            // Inclui quem editou: uma aba antiga dele não pode reverter a edição.
            null
        );
    }

    /**
     * O ator não é excluído: remover de novo não faz nada, e assim as outras abas
     * dele também tiram a mensagem.
     */
    public function whenMessagePurged(MessageWasPurged $event): void
    {
        $this->broadcaster->toChannelMembers(
            $event->channel,
            self::EVENT_MESSAGE_PURGED,
            [
                'id'        => $event->messageId,
                'channelId' => (int) $event->channel->id,

                // Para o painel da thread remover também e recontar a raiz.
                'threadId'  => $event->threadId,
            ],
            null
        );
    }

    public function whenReactionToggled(ReactionToggled $event): void
    {
        $channel = $event->message->channel;

        if ($channel === null) {
            return;
        }

        $this->broadcaster->toChannelMembers(
            $channel,
            self::EVENT_REACTION,
            [
                'messageId' => (int) $event->message->id,
                'channelId' => (int) $event->message->channel_id,
                'emoji'     => $event->emoji,
                'userId'    => (int) $event->actor->id,
                'added'     => $event->added,
            ],
            (int) $event->actor->id
        );
    }

    public function whenThreadChanged(ThreadWasCreated|ThreadWasEdited $event): void
    {
        $channel = $event->thread->channel;

        if ($channel === null) {
            return;
        }

        $this->broadcaster->toChannelMembers(
            $channel,
            self::EVENT_THREAD,
            [
                'threadId'          => (int) $event->thread->id,
                'channelId'         => (int) $event->thread->channel_id,
                'originalMessageId' => $event->thread->original_message_id,
                'title'             => $event->thread->title,
                'repliesCount'      => (int) $event->thread->replies_count,

                // Até onde a contagem já chega. Criar uma thread gera este evento
                // e o da mensagem; o cliente usa isto para não contar a resposta
                // duas vezes.
                'lastMessageId'     => $event->thread->last_message_id,
            ],
            $event->actor?->id
        );
    }

    public function whenChannelChanged(ChannelStatusChanged|ChannelWasEdited $event): void
    {
        $this->broadcaster->toChannelMembers(
            $event->channel,
            self::EVENT_CHANNEL,
            [
                'channelId' => (int) $event->channel->id,
                'status'    => $event->channel->status,

                // Configurações que mudam o que o cliente desenha, sem recarregar.
                // `canPostMessage` fica de fora porque varia por usuário; o cliente
                // busca o próprio registro de novo.
                'postPermission' => $event->channel->post_permission,
                'isPrivate'      => (bool) $event->channel->is_private,
                'threadingEnabled' => (bool) $event->channel->threading_enabled,

                // A parte por usuário (`slowModeRemaining`) o cliente busca de
                // novo, como `canPostMessage`.
                'slowModeSeconds' => (int) $event->channel->slow_mode_seconds,
                'name'           => $event->channel->name,
                'emoji'          => $event->channel->emoji,
                'description'    => $event->channel->description,
                'imageUrl'       => $event->channel->imageUrl(),

                // Sempre enviados, para sair do arquivo limpar em toda tela.
                'archivedAt'           => $event->channel->archived_at?->toIso8601String(),
                'archivedDiscussionId' => $event->channel->archived_discussion_id,
            ],
            // Sem excluir o ator: repetir é inofensivo e mantém as abas dele em
            // sincronia.
            null
        );
    }

    /**
     * Canal novo, para quem já está nele além do criador (o outro lado de uma
     * conversa direta). Só ids: cada um lê o canal pela API, que decide o que ele
     * pode ver.
     */
    public function whenChannelCreated(ChannelWasCreated $event): void
    {
        $this->broadcaster->toChannelMembers(
            $event->channel,
            self::EVENT_CHANNEL,
            [
                'channelId' => (int) $event->channel->id,
                'status'    => $event->channel->status,
                'created'   => true,
            ],
            $event->actor?->id,
            queue: (int) $event->channel->user_count >= self::LARGE_AUDIENCE
        );
    }

    /**
     * Avisa as duas salas. Nenhum lado recebe o id do outro: o destino pode ser um
     * canal que os membros da origem não veem, e vice-versa.
     */
    public function whenMessagesMoved(MessagesWereMoved $event): void
    {
        $threads = [];

        foreach (Thread::query()->whereKey(array_values(array_unique($event->threadIds)))->get() as $thread) {
            $threads[(int) $thread->channel_id][] = [
                'threadId'          => (int) $thread->id,
                'repliesCount'      => (int) $thread->replies_count,
                'originalMessageId' => $thread->original_message_id,
                'lastMessageId'     => $thread->last_message_id,
            ];
        }

        $movedIn = [];

        foreach ($event->messageIdsBySource as $sourceId => $messageIds) {
            $messageIds = array_map('intval', $messageIds);
            $movedIn = array_merge($movedIn, $messageIds);

            $source = $event->sources[$sourceId] ?? null;

            if ($source === null) {
                continue;
            }

            $this->broadcaster->toChannelMembers(
                $source,
                self::EVENT_MESSAGES_MOVED,
                [
                    'channelId'     => (int) $sourceId,
                    'movedOut'      => $messageIds,

                    // Para o painel de thread aberto remover também.
                    'threadIds'     => array_intersect_key($event->threadIds, array_flip($messageIds)),
                    'threads'       => $threads[(int) $sourceId] ?? [],
                    'messagesCount' => (int) $source->messages_count,
                ],
                null
            );
        }

        $this->broadcaster->toChannelMembers(
            $event->target,
            self::EVENT_MESSAGES_MOVED,
            [
                'channelId'     => (int) $event->target->id,
                'movedIn'       => $movedIn,

                // A mudança regravou cada linha; filtrar por este `updated_at`
                // deixa de fora as que já estavam no destino.
                'movedAt'       => Carbon::now()->subSecond()->getTimestamp(),
                'messagesCount' => (int) $event->target->messages_count,
            ],
            null
        );
    }

    /**
     * A sala ouve porque o papel aparece na lista de membros; o envolvido busca de
     * novo o que pode fazer.
     */
    public function whenModeratorChanged(ChannelModeratorChanged $event): void
    {
        $this->membership(
            $event->channel,
            $event->user,
            $event->isModerator ? 'promoted' : 'demoted',
            $event->actor,
            tellMembers: true
        );

        $this->pushRanks($event->channel);
    }

    /**
     * A sala toda ouve, porque o selo de dono muda. `previousOwnerId` avisa o dono
     * anterior que o push também é com ele.
     */
    public function whenOwnershipTransferred(ChannelOwnershipTransferred $event): void
    {
        $this->membership(
            $event->channel,
            $event->newOwner,
            'owner_changed',
            $event->newOwner,
            tellMembers: true,
            extra: ['previousOwnerId' => $event->previousOwner?->id !== null ? (int) $event->previousOwner->id : null]
        );

        $this->pushRanks($event->channel);
    }

    /**
     * Transferência oferecida: avisa quem recebe e quem iniciou.
     */
    public function whenTransferRequested(OwnershipTransferRequested $event): void
    {
        $this->membership($event->channel, $event->to, 'transfer_requested', $event->from, tellMembers: false);
    }

    /**
     * Transferência retirada, recusada ou substituída: só as duas partes ouvem.
     */
    public function whenTransferEnded(OwnershipTransferEnded $event): void
    {
        $to = User::query()->find($event->transfer->to_user_id);
        $from = User::query()->find($event->transfer->from_user_id);

        if ($to === null) {
            return;
        }

        $this->membership(
            $event->channel,
            $to,
            'transfer_ended',
            $from,
            tellMembers: false,
            extra: ['reason' => $event->reason]
        );
    }

    /**
     * Cada moderador reconta o próprio contador. Só ids e contagens, nunca a
     * mensagem: quem não lê o canal recebe um id sem nada a aplicar.
     */
    public function whenFlagsChanged(FlagsChanged $event): void
    {
        $ids = array_values(array_unique(array_map('intval', $event->messageIds)));

        $open = $ids === [] ? [] : MessageFlag::query()
            ->whereIn('message_id', $ids)
            ->whereNull('resolved_at')
            ->groupBy('message_id')
            ->selectRaw('message_id, count(*) as open_count')
            ->pluck('open_count', 'message_id')
            ->all();

        $this->broadcaster->toModerators(self::EVENT_FLAGS, [
            'messages' => array_map(fn (int $id) => [
                'id'         => $id,
                'flagsCount' => (int) ($open[$id] ?? 0),
            ], $ids),
        ]);
    }

    /**
     * Canal removido: só ids, para a sidebar tirar a linha. A audiência ainda se
     * resolve porque as filiações ficam, e o SendChatEventJob pula o scope de
     * visibilidade para canal apagado.
     */
    public function whenChannelDeleted(ChannelWasDeleted $event): void
    {
        $this->broadcaster->toChannelMembers(
            $event->channel,
            self::EVENT_CHANNEL,
            [
                'channelId' => (int) $event->channel->id,
                'status'    => $event->channel->status,
                'deleted'   => true,
            ],
            null
        );
    }

    /**
     * Arquivar congela o canal, então o composer some na hora. Leva a discussão da
     * transcrição, para onde o aviso aponta.
     */
    public function whenChannelArchived(ChannelWasArchived $event): void
    {
        $this->broadcaster->toChannelMembers(
            $event->channel,
            self::EVENT_CHANNEL,
            [
                'channelId'            => (int) $event->channel->id,
                'status'               => $event->channel->status,
                'archivedAt'           => $event->channel->archived_at?->toIso8601String(),
                'archivedDiscussionId' => (int) $event->discussion->id,
            ],
            null
        );
    }

    public function whenInvited(UserWasInvited $event): void
    {
        $this->membership($event->channel, $event->user, 'invited', $event->inviter, tellMembers: false);
    }

    public function whenJoined(UserJoinedChannel $event): void
    {
        // Entrada oculta: só as abas do próprio membro sabem.
        $this->membership($event->channel, $event->user, 'joined', $event->actor, tellMembers: ! $event->hidden);

        if (! $event->hidden && $this->holdsRank($event->channel, $event->user)) {
            $this->pushRanks($event->channel);
        }
    }

    public function whenLeft(UserLeftChannel $event): void
    {
        $this->membership($event->channel, $event->user, 'left', $event->actor, tellMembers: ! $event->hidden);

        if (! $event->hidden && $this->holdsRank($event->channel, $event->user)) {
            $this->pushRanks($event->channel);
        }
    }

    /**
     * Sem excluir o ator: o editor dele já redesenhou, as outras abas não.
     */
    public function whenRanksChanged(ChannelRanksChanged $event): void
    {
        $this->pushRanks($event->channel);
    }

    protected function pushRanks(Channel $channel): void
    {
        $book = $this->ranks->book($channel);

        if ($book === null) {
            return;
        }

        $this->broadcaster->toChannelMembers($channel, self::EVENT_RANKS, [
            'channelId' => (int) $channel->id,
            'rankBook'  => $book,
        ], null);
    }

    /**
     * Só muda os cargos quem aparece com um. O dono aparece estando ou não.
     */
    protected function holdsRank(Channel $channel, User $user): bool
    {
        if (! $channel->isCategory()) {
            return false;
        }

        return ChannelRankUser::query()->where('channel_id', $channel->id)->where('user_id', $user->id)->exists()
            || ChannelUser::query()->where('channel_id', $channel->id)->where('user_id', $user->id)->where('is_moderator', true)->exists();
    }

    public function whenInviteDeclined(InviteWasDeclined $event): void
    {
        // O convidador entra como ator para a aba de membros dele ouvir; a recusa
        // em si chega como notificação.
        $this->membership($event->channel, $event->user, 'invite_declined', $event->inviter, tellMembers: false);
    }

    public function whenInviteCancelled(InviteWasCancelled $event): void
    {
        $this->membership($event->channel, $event->user, 'invite_cancelled', $event->actor, tellMembers: false);
    }

    /**
     * A pessoa envolvida sempre ouve; o ator também, se for outro. A sala só ouve
     * entradas e saídas, nunca convites. Só ids e nomes: o cliente busca o canal
     * pela API, onde o scope decide o que ele pode ver.
     *
     * @param  array<string, mixed>  $extra  Escalares que uma ação específica acrescenta.
     */
    protected function membership(
        Channel $channel,
        User $user,
        string $action,
        ?User $actor,
        bool $tellMembers,
        array $extra = []
    ): void {
        $payload = [
            'channelId'   => (int) $channel->id,
            'channelName' => $channel->name,
            'isPrivate'   => (bool) $channel->is_private,
            'userId'      => (int) $user->id,
            'username'    => $user->display_name,
            'action'      => $action,
            'actorId'     => $actor?->id !== null ? (int) $actor->id : null,
            'actorName'   => $actor?->display_name,
            'userCount'   => (int) $channel->user_count,
        ] + $extra;

        $this->broadcaster->toUser((int) $user->id, self::EVENT_MEMBERSHIP, $payload);

        if ($tellMembers) {
            $this->broadcaster->toChannelMembers($channel, self::EVENT_MEMBERSHIP, $payload, (int) $user->id);

            return;
        }

        if ($actor !== null && (int) $actor->id !== (int) $user->id) {
            $this->broadcaster->toUser((int) $actor->id, self::EVENT_MEMBERSHIP, $payload);
        }
    }

    /**
     * A mensagem no fio. O conteúdo vai junto, não só o id, para não gerar um
     * fetch por mensagem; é seguro porque o SendChatEventJob filtra a audiência
     * pelo mesmo scope da API. Apagada não leva `contentHtml`.
     *
     * @return array<string, mixed>
     */
    /**
     * Conteúdo renderizado, ou texto escapado se o formatter lançar: isto roda na
     * request de envio, com a linha já gravada, e não pode virar 500.
     */
    protected function contentHtml(Message $message): string
    {
        try {
            return $message->formatContent();
        } catch (\Throwable $e) {
            $this->log->error('[ramon/chat] formatContent failed while broadcasting message '.$message->id.': '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $message->fallbackContentHtml();
        }
    }

    protected function messagePayload(Message $message): array
    {
        $deleted = $message->isDeleted();

        return [
            'id'          => (int) $message->id,
            'channelId'   => (int) $message->channel_id,
            'threadId'    => $message->thread_id,
            'replyToId'   => $message->reply_to_id,
            'number'      => $message->number,
            'userId'      => $message->user_id,
            'type'        => $message->type,
            'systemKey'   => $message->system_key,

            // Os placeholders que a tradução da mensagem de sistema interpola.
            'systemData'  => $message->system_data,
            'contentHtml' => $deleted || $message->isSystem() ? null : $this->contentHtml($message),
            'createdAt'   => $message->created_at?->toIso8601String(),
            'editedAt'    => $message->edited_at?->toIso8601String(),
            'isDeleted'   => $deleted,

            // Igual ao MessageResource, pela coluna: apagar a própria mensagem não
            // é moderação.
            'isModeratorDeleted' => $deleted
                && $message->deleted_by_id !== null
                && (int) $message->deleted_by_id !== (int) $message->user_id,

            // Nunca enviado: o payload vai a todos os membros, e quem apagou só o
            // autor e a moderação veem (MessageResource). A chave fica para não
            // mudar o formato que o cliente lê.
            'deletedById' => null,

            'isPinned'    => $message->isPinned(),
            'pinnedAt'    => $message->pinned_at?->toIso8601String(),

            // O cliente monta a linha só com este payload, sem novo fetch, então
            // anexos, menções e autor precisam vir junto.
            'uploads'     => $deleted ? [] : $this->uploadsPayload($message),

            // Usado no destaque e no som de canais em "só menções".
            'mentionedUsers'      => $deleted ? [] : $message->mentions
                ->where('type', 'user')
                ->pluck('user_id')
                ->filter()
                ->values()
                ->all(),

            'mentionsChannelWide' => ! $deleted
                && $message->mentions->contains(fn ($mention) => $mention->isChannelWide()),

            // `userId` só resolve se o autor já estiver no store do cliente.
            'user'        => $this->userPayload($message),

            // O cargo também: quem não carregou os cargos do canal não teria de onde ler.
            'authorRank'  => $message->user_id !== null && $message->channel !== null
                ? $this->ranks->forUser($message->channel, (int) $message->user_id)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function userPayload(Message $message): ?array
    {
        $user = $message->relationLoaded('user') ? $message->user : $message->user()->first();

        // `instanceof` serve de checagem de null e de narrowing: a relação vem
        // tipada como Model.
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id'          => (int) $user->id,
            'username'    => $user->username,
            'displayName' => $user->display_name,
            'avatarUrl'   => $user->avatar_url,
            'slug'        => (string) ($user->slug ?? $user->id),
            'groups'      => $this->groupsPayload($user),
        ];
    }

    /**
     * Grupos do autor, para os badges. Grupos ocultos ficam de fora: o payload é
     * o mesmo para todos, e o core só os mostra a quem tem `viewHiddenGroups`.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function groupsPayload(User $user): array
    {
        $groups = $user->relationLoaded('groups') ? $user->groups : $user->groups()->get();

        return $groups
            ->filter(fn ($group) => ! $group->is_hidden)
            ->map(fn ($group) => [
                'id'           => (int) $group->id,
                'nameSingular' => $group->name_singular,
                'namePlural'   => $group->name_plural,
                'color'        => $group->color,
                'icon'         => $group->icon,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function uploadsPayload(Message $message): array
    {
        // O envio carrega outras relações, mas não `uploads`.
        $uploads = $message->relationLoaded('uploads')
            ? $message->uploads
            : $message->uploads()->get();

        return $uploads
            ->map(fn (Upload $upload) => [
                'id'        => (int) $upload->id,
                'fileName'  => $upload->file_name,
                'mimeType'  => $upload->mime_type,
                'size'      => (int) $upload->size,
                // Para o cliente reservar espaço e a linha não pular ao carregar.
                'width'     => $upload->width,
                'height'    => $upload->height,
                'url'       => $upload->url(),
                'isImage'   => $upload->isImage(),
                'createdAt' => $upload->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
