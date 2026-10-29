<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Locale\Translator;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Ramon\Chat\Event\ThreadWasEdited;
use Ramon\Chat\Service\UnreadTracker;
use Ramon\Chat\Thread;
use Ramon\Chat\ThreadUser;
use Tobyz\JsonApiServer\Context as OriginalContext;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * @extends AbstractDatabaseResource<Thread>
 */
class ThreadResource extends AbstractDatabaseResource
{
    public function __construct(
        protected Translator $translator,
        protected Events $events,
        protected UnreadTracker $unread
    ) {
    }

    public function type(): string
    {
        return 'chat-threads';
    }

    public function model(): string
    {
        return Thread::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    /**
     * O que uma página de threads precisa carregado antes de serializar. Include
     * só decide o que vai na resposta; o serializer ainda busca a relação por
     * linha, e a mensagem raiz (via MessageResource) custaria ~7 queries cada.
     *
     * As duas relações de mensagem ficam com `scopedMessages()`: o que o
     * `eagerLoad` traz o buffer do core pula, e só o buffer aplica o scope do
     * MessageResource. Uma raiz movida para canal privado vazaria em `included`.
     */
    protected const EAGER_LOAD = [
        'channel',
        'creator',
        'lastMessage.user',
        'originalMessage.user.groups',
        'originalMessage.channel',
        'originalMessage.reactions',
        'originalMessage.uploads',
        'originalMessage.mentions',
        'originalMessage.flags',
    ];

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()
                // Ler o chat exige conta. Sem a trava, estes endpoints responderiam
                // 200 com coleção vazia a qualquer um.
                ->authenticated()
                ->defaultInclude(['creator', 'originalMessage', 'originalMessage.user'])
                // `channel` porque toda policy de thread o lê, inclusive a de
                // visibilidade.
                ->eagerLoad(self::EAGER_LOAD)
                ->eagerLoadWhere('originalMessage', $this->scopedMessages(...))
                ->eagerLoadWhere('lastMessage', $this->scopedMessages(...))
                ->eagerLoadWhere(
                    'actorMembership',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                )
                ->eagerLoadWhere(
                    'originalMessage.bookmarks',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                ),

            Endpoint\Index::make()
                ->authenticated()
                ->defaultSort('-lastMessageAt')
                ->defaultInclude(['creator', 'originalMessage', 'originalMessage.user'])
                ->eagerLoad(self::EAGER_LOAD)
                ->eagerLoadWhere('originalMessage', $this->scopedMessages(...))
                ->eagerLoadWhere('lastMessage', $this->scopedMessages(...))
                // Campos e policies leem a membership do ator; carregada uma vez
                // por página. `membershipFor()` confere que a linha é do ator.
                ->eagerLoadWhere(
                    'actorMembership',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                )
                ->eagerLoadWhere(
                    'originalMessage.bookmarks',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                )
                // Depois de o core carregar as relações acima, num hook registrado
                // antes.
                ->beforeSerialization(fn (Context $context, array $results) => $this->shareChannels($results['models']))
                ->paginate(30),

            // ->can(), não visible(): resolve a ability contra $context->model, como
            // o core faz.
            Endpoint\Update::make()
                ->authenticated()
                // Closure, não string: o Flarum chama ability callable, e 'rename'
                // é a rename() do PHP.
                ->can(fn () => 'rename')
                // Hook `after`, não `action()`: a action do Update é quem preenche
                // e salva o model.
                ->after(function (Context $context, Thread $thread) {
                    if ($thread->wasChanged()) {
                        $this->events->dispatch(new ThreadWasEdited($thread, $context->getActor()));
                    }

                    return $thread;
                }),

            Endpoint\Endpoint::make('read')
                ->route('POST', '/{id}/read')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Thread $thread */
                    $thread = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('view', $thread)) {
                        throw new ForbiddenException();
                    }

                    $upTo = Arr::get($context->body(), 'data.attributes.lastReadMessageId');

                    $this->unread->markThreadRead(
                        $thread,
                        $actor,
                        $upTo === null ? null : (int) $upTo
                    );
                })
                ->response(fn () => new EmptyResponse(204)),

            // Nível de acompanhamento por thread (o sino no cabeçalho da thread).
            Endpoint\Endpoint::make('tracking')
                ->route('POST', '/{id}/tracking')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Thread $thread */
                    $thread = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('view', $thread)) {
                        throw new ForbiddenException();
                    }

                    $level = (int) Arr::get($context->body(), 'data.attributes.notificationLevel', ThreadUser::LEVEL_ALWAYS);

                    if (! in_array($level, [ThreadUser::LEVEL_NEVER, ThreadUser::LEVEL_MENTIONS, ThreadUser::LEVEL_ALWAYS], true)) {
                        $level = ThreadUser::LEVEL_ALWAYS;
                    }

                    $membership = ThreadUser::query()
                        ->where('thread_id', $thread->id)
                        ->where('user_id', $actor->id)
                        ->first();

                    if ($membership === null) {
                        $membership = new ThreadUser();
                        $membership->thread_id = $thread->id;
                        $membership->user_id = $actor->id;
                    }

                    $membership->notification_level = $level;
                    $membership->save();

                    // A resposta serializa esta thread e lê a membership de novo;
                    // entregar a linha recém-salva evita o memo responder com o
                    // valor anterior.
                    $thread->forgetMembership($actor);
                    $thread->setRelation('actorMembership', $membership);

                    return $thread;
                }),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title')
                ->nullable()
                ->maxLength(200)
                ->writable(fn (Thread $t, Context $context) => $context->getActor()->can('rename', $t)),

            Schema\Str::make('status'),

            Schema\Integer::make('channelId'),
            Schema\Integer::make('originalMessageId')->nullable(),
            Schema\Integer::make('repliesCount'),
            Schema\Integer::make('lastMessageId')->nullable(),
            Schema\DateTime::make('lastMessageAt')->nullable(),
            Schema\DateTime::make('createdAt'),

            // ── Acompanhamento do ator ──
            Schema\Integer::make('notificationLevel')
                ->get(fn (Thread $t, Context $context) => $this->membership($t, $context->getActor())->notification_level
                    ?? ThreadUser::LEVEL_NEVER),

            Schema\Integer::make('unreadCount')
                ->get(fn (Thread $t, Context $context) => $this->membership($t, $context->getActor())->unread_count ?? 0),

            Schema\Integer::make('lastReadMessageId')
                ->get(fn (Thread $t, Context $context) => $this->membership($t, $context->getActor())->last_read_message_id ?? 0),

            Schema\Boolean::make('isParticipating')
                ->get(fn (Thread $t, Context $context) => $this->membership($t, $context->getActor()) !== null),

            Schema\Boolean::make('canRename')
                ->get(fn (Thread $t, Context $context) => $context->getActor()->can('rename', $t)),

            Schema\Boolean::make('canPostMessage')
                ->get(fn (Thread $t, Context $context) => $context->getActor()->can('postMessage', $t)),

            Schema\Boolean::make('canClose')
                ->get(fn (Thread $t, Context $context) => $context->getActor()->can('close', $t)),

            Schema\Relationship\ToOne::make('creator')->type('users')->includable(),
            Schema\Relationship\ToOne::make('channel')->type('chat-channels')->includable(),
            Schema\Relationship\ToOne::make('originalMessage')->type('chat-messages')->includable(),
            Schema\Relationship\ToOne::make('lastMessage')->type('chat-messages')->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('lastMessageAt'),
            SortColumn::make('createdAt'),
            SortColumn::make('repliesCount'),
        ];
    }


    /**
     * Restringe uma relação de mensagem ao que o ator pode ler. Registrada antes
     * dos paths `originalMessage.*` de propósito: `loadMissing()` carregaria o
     * primeiro segmento sem restrição.
     */
    protected function scopedMessages(mixed $query, Context $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    /**
     * Dá a cada mensagem raiz a mesma instância de canal da thread. Carregados à
     * parte, viriam duas cópias, e cada uma buscaria a membership de novo. Raiz
     * movida para outro canal fica com o seu.
     *
     * @param  iterable<Thread>  $threads
     */
    protected function shareChannels(iterable $threads): void
    {
        foreach ($threads as $thread) {
            $message = $thread->relationLoaded('originalMessage') ? $thread->originalMessage : null;
            $channel = $thread->relationLoaded('channel') ? $thread->channel : null;

            if ($message !== null && $channel !== null && (int) $message->channel_id === (int) $channel->id) {
                $message->setRelation('channel', $channel);
            }
        }
    }

    /** @var array<int, ThreadUser|null|false> */
    protected array $membershipCache = [];

    protected function membership(Thread $thread, User $actor): ?ThreadUser
    {
        if (! $actor->exists) {
            return null;
        }

        if (! array_key_exists($thread->id, $this->membershipCache)) {
            $this->membershipCache[$thread->id] = $thread->membershipFor($actor);
        }

        return $this->membershipCache[$thread->id] ?: null;
    }
}
