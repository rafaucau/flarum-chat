<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Resource;

use Carbon\Carbon;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Post\Exception\FloodingException;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Bookmark;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\MessagePinToggled;
use Ramon\Chat\Event\MessageWasDeleted;
use Ramon\Chat\Event\MessageWasEdited;
use Ramon\Chat\Event\MessageWasPurged;
use Ramon\Chat\Event\MessageWasRestored;
use Ramon\Chat\Event\ReactionToggled;
use Ramon\Chat\Mention\MentionResolver;
use Ramon\Chat\Message;
use Ramon\Chat\MessageReaction;
use Ramon\Chat\MessageRevision;
use Ramon\Chat\Service\ActionThrottle;
use Ramon\Chat\Service\ChannelRanks;
use Ramon\Chat\Service\MessageDispatcher;
use Ramon\Chat\Thread;
use Tobyz\JsonApiServer\Context as OriginalContext;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * @extends AbstractDatabaseResource<Message>
 */
class MessageResource extends AbstractDatabaseResource
{
    public function __construct(
        protected Translator $translator,
        protected Events $events,
        protected MessageDispatcher $dispatcher,
        protected MentionResolver $mentions,
        protected LoggerInterface $log,
        protected ActionThrottle $throttle,
        protected ChannelRanks $ranks
    ) {
    }

    /**
     * Limites de reações, que qualquer leitor pode criar. Vinte tipos já passam do
     * que a fileira de chips mostra; dez de uma pessoa é usar a barra de teclado.
     */
    protected const MAX_DISTINCT_REACTIONS = 20;

    protected const MAX_REACTIONS_PER_USER = 10;

    public function type(): string
    {
        return 'chat-messages';
    }

    public function model(): string
    {
        return Message::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    /**
     * Só conta a coleção quando o cliente lê o total (a listagem de fixadas). Nas
     * outras páginas o COUNT(*) varreria todo o histórico do canal à toa.
     */
    public function count(object $query, OriginalContext $context): ?int
    {
        $filter = $context->queryParam('filter');

        if (! is_array($filter) || ! array_key_exists('pinned', $filter)) {
            return null;
        }

        return parent::count($query, $context);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()
                // Ler o chat exige conta. Sem a trava, estes endpoints responderiam
                // 200 com coleção vazia a qualquer um.
                ->authenticated()
                ->defaultInclude(['user', 'user.groups', 'replyTo', 'replyTo.user', 'uploads', 'thread', 'thread.lastMessage', 'deletedBy'])
                ->eagerLoad(['user.groups', 'reactions', 'uploads', 'mentions', 'flags', 'channel'])
                ->eagerLoadWhere('bookmarks', fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)),

            Endpoint\Index::make()
                ->authenticated()
                // Mais novas primeiro: o canal abre no fim e pagina para trás.
                ->defaultSort('-id')
                // `thread` precisa ser *incluída*: eagerLoad não põe o registro em
                // `included`, e o cliente lê `message.thread()` para o indicador de
                // respostas. `thread.lastMessage` dá a prévia; `user.groups`, os
                // badges do autor, como no PostResource do core.
                ->defaultInclude(['user', 'user.groups', 'replyTo', 'replyTo.user', 'uploads', 'thread', 'thread.lastMessage', 'deletedBy'])
                // `channel` porque as policies das flags o leem, e `bookmarks` por
                // causa de `isBookmarked`; sem isso, uma query por linha. Bookmarks
                // só do ator: o campo só pergunta por ele.
                ->eagerLoad(['user.groups', 'reactions', 'uploads', 'mentions', 'flags', 'channel'])
                // Scope à mão: relação já carregada pula o buffer do core, que é
                // quem aplica o scope do ThreadResource; thread apagada vazaria.
                ->eagerLoadWhere('thread', fn ($query, Context $context) => $query->whereVisibleTo($context->getActor()))
                ->eagerLoadWhere('bookmarks', fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id))
                // Máximo 100 para pular a uma mensagem antiga (fixada, citada) em
                // poucas requisições.
                ->paginate(50, 100),

            // A criação passa pelo MessageDispatcher: menções, threads, uploads,
            // contadores e não lidas numa transação só. Ainda é Endpoint\Create,
            // porque o setUp() dele registra rota, hook de includes e a resposta
            // 201; ->action() troca só a action.
            Endpoint\Create::make()
                ->authenticated()
                ->action(function (Context $context) {
                    $attributes = Arr::get($context->body(), 'data.attributes', []);

                    $channel = $this->findChannel($context, (int) Arr::get($attributes, 'channelId'));
                    $actor = $context->getActor();

                    if (! $actor->can('postMessage', $channel)) {
                        throw new ForbiddenException();
                    }

                    $thread = $this->findThread($context, Arr::get($attributes, 'threadId'), $channel);
                    $replyTo = $this->findReplyTarget($context, Arr::get($attributes, 'replyToId'));

                    $createThread = (bool) Arr::get($attributes, 'createThread', false);

                    if ($createThread && ! $channel->threading_enabled) {
                        throw new ValidationException([
                            'createThread' => $this->translator->trans('ramon-chat.api.threading_disabled'),
                        ]);
                    }

                    // Conferido aqui também: `canCreateThread` só decide se o botão
                    // aparece, e a requisição pode trazer `createThread` de qualquer jeito.
                    if ($createThread && $replyTo !== null && ! $actor->can('createThread', $replyTo)) {
                        throw new ForbiddenException();
                    }

                    $message = $this->dispatcher->send(
                        channel: $channel,
                        actor: $actor,
                        content: (string) Arr::get($attributes, 'content', ''),
                        thread: $thread,
                        replyTo: $replyTo,
                        uploadIds: (array) Arr::get($attributes, 'uploadIds', []),
                        createThread: $createThread
                    );

                    // Para `authorRank` responder na cópia de quem enviou: só lê de
                    // canal carregado (ver o campo).
                    $message->setRelation('channel', $channel);

                    return $message;
                })
                ->defaultInclude(['user', 'user.groups', 'replyTo', 'replyTo.user', 'uploads', 'thread']),

            Endpoint\Endpoint::make('edit')
                ->route('PATCH', '/{id}')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('edit', $message)) {
                        throw new ForbiddenException();
                    }

                    $content = trim((string) Arr::get($context->body(), 'data.attributes.content', ''));

                    if ($content === '') {
                        throw new ValidationException([
                            'content' => $this->translator->trans('ramon-chat.api.message_empty'),
                        ]);
                    }

                    // A policy já confirmou que o canal aceita o ator; aqui entram as
                    // regras de conteúdo do envio.
                    /** @var Channel $channel */
                    $channel = $message->channel;
                    $this->dispatcher->assertMayRevise($channel, $actor, $content);

                    $message->reviseContent($content, $actor);
                    $message->save();

                    // Recalcula menções: tirar uma menção na edição tira também a
                    // notificação que ela gerou.
                    $this->mentions->sync(
                        $message,
                        $message->channel !== null && $actor->can('mentionChannelWide', $message->channel)
                    );

                    $this->events->dispatch(new MessageWasEdited($message, $actor));

                    return $message;
                })
                ->defaultInclude(['user', 'user.groups', 'replyTo', 'replyTo.user', 'uploads']),

            Endpoint\Endpoint::make('remove')
                ->route('POST', '/{id}/delete')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('delete', $message)) {
                        throw new ForbiddenException();
                    }

                    $message->deleted_at = Carbon::now();
                    $message->deleted_by_id = $actor->id;
                    $message->save();

                    $this->refreshContainers($message);

                    $this->events->dispatch(new MessageWasDeleted($message, $actor));
                })
                ->response(fn () => new EmptyResponse(204)),

            // Remove a linha de vez, tombstone inclusa. É um segundo passo após o
            // delete, porque não tem volta.
            Endpoint\Endpoint::make('purge')
                ->route('POST', '/{id}/purge')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('forceDelete', $message)) {
                        throw new ForbiddenException();
                    }

                    // Guardado antes de a linha sumir, para saber o que recontar.
                    $channel = $message->channel;
                    $thread = $message->thread;

                    // Idem.
                    $messageId = (int) $message->id;
                    $threadId = $message->thread_id;

                    // Reações, menções, revisões, bookmarks, denúncias e uploads caem
                    // em cascata pela FK. Os arquivos já foram apagados no delete.
                    $message->delete();

                    // `chat_channels.last_message_id` e os contadores da thread não
                    // têm FK; sem isto a sidebar apontaria para uma linha inexistente.
                    $channel?->refreshMetadata()->save();
                    $thread?->refreshMetadata()->save();

                    // Anunciado para a linha sair do stream de todos. Os outros
                    // deletes viram tombstone e vão no evento de mensagem alterada;
                    // o purge não deixa nada para redesenhar.
                    if ($channel !== null) {
                        $this->events->dispatch(
                            new MessageWasPurged($messageId, $channel, $threadId, $actor)
                        );
                    }
                })
                ->response(fn () => new EmptyResponse(204)),

            Endpoint\Endpoint::make('restore')
                ->route('POST', '/{id}/restore')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('restore', $message)) {
                        throw new ForbiddenException();
                    }

                    $message->deleted_at = null;
                    $message->deleted_by_id = null;
                    $message->save();

                    $this->refreshContainers($message);

                    $this->events->dispatch(new MessageWasRestored($message, $actor));

                    return $message;
                })
                ->defaultInclude(['user', 'user.groups', 'uploads']),

            Endpoint\Endpoint::make('react')
                ->route('POST', '/{id}/react')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('react', $message)) {
                        throw new ForbiddenException();
                    }

                    // Conta nos dois sentidos: remover também é escrita e push para
                    // todos os membros.
                    if (! $this->throttle->attempt('react.'.$actor->id, 15, 10)) {
                        throw new FloodingException();
                    }

                    $emoji = $this->normaliseEmoji(
                        (string) Arr::get($context->body(), 'data.attributes.emoji', '')
                    );

                    /** @var MessageReaction|null $existing */
                    $existing = MessageReaction::query()
                        ->where('message_id', $message->id)
                        ->where('user_id', $actor->id)
                        ->where('emoji', $emoji)
                        ->first();

                    if ($existing !== null) {
                        $existing->delete();
                        $added = false;
                    } else {
                        $this->assertRoomForReaction($message, $actor, $emoji);

                        $reaction = new MessageReaction();
                        $reaction->message_id = $message->id;
                        $reaction->user_id = $actor->id;
                        $reaction->emoji = $emoji;
                        $reaction->created_at = Carbon::now();

                        // Dois cliques em corrida passam pela busca acima e tentam
                        // inserir; o índice único fica com um, e o outro vê a reação
                        // existente em vez de um 500.
                        try {
                            $reaction->save();
                        } catch (UniqueConstraintViolationException) {
                            $message->unsetRelation('reactions');

                            return $message;
                        }

                        $added = true;
                    }

                    $message->unsetRelation('reactions');

                    // Reação mora em outra tabela, então a linha da mensagem não
                    // muda, e o polling de fallback reconcilia por essa coluna. O
                    // touch faz a reação chegar sem websocket (ver MessageChangedFilter).
                    $message->touch();

                    $this->events->dispatch(new ReactionToggled($message, $actor, $emoji, $added));

                    return $message;
                })
                ->defaultInclude(['user', 'user.groups', 'uploads']),

            Endpoint\Endpoint::make('bookmark')
                ->route('POST', '/{id}/bookmark')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('bookmark', $message)) {
                        throw new ForbiddenException();
                    }

                    if (! $this->throttle->attempt('bookmark.'.$actor->id, 30, 10)) {
                        throw new FloodingException();
                    }

                    // Validado antes da coluna: passar de 200 caracteres daria erro de
                    // banco (500), e um não-string ficaria a critério do driver.
                    $name = Arr::get($context->body(), 'data.attributes.name');

                    if ($name !== null && (! is_string($name) || mb_strlen($name) > 200)) {
                        throw new ValidationException([
                            'name' => $this->translator->trans('ramon-chat.api.bookmark_name_too_long', ['max' => 200]),
                        ]);
                    }

                    $name = $name === null ? null : (trim($name) ?: null);

                    /** @var Bookmark|null $existing */
                    $existing = Bookmark::query()
                        ->where('message_id', $message->id)
                        ->where('user_id', $actor->id)
                        ->first();

                    if ($existing !== null) {
                        $existing->delete();
                    } else {
                        $bookmark = new Bookmark();
                        $bookmark->message_id = $message->id;
                        $bookmark->user_id = $actor->id;
                        $bookmark->name = $name;
                        $bookmark->save();
                    }

                    return $message;
                })
                ->defaultInclude(['user', 'user.groups']),

            // Histórico de edições em endpoint próprio, não relação: é lido raramente,
            // e no schema mandaria toda versão de toda mensagem do stream.
            Endpoint\Endpoint::make('revisions')
                ->route('GET', '/{id}/revisions')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('viewRevisions', $message)) {
                        throw new ForbiddenException();
                    }

                    $revisions = $message->revisions()
                        ->with('editedBy')
                        ->orderBy('id')
                        ->get();

                    return $revisions->map(fn (MessageRevision $revision) => [
                        'id'        => (int) $revision->id,
                        'content'   => $this->revisionContent($revision),
                        'createdAt' => $revision->created_at?->toIso8601String(),
                        'editedBy'  => $revision->editedBy === null ? null : [
                            'id'          => (int) $revision->editedBy->id,
                            'username'    => $revision->editedBy->username,
                            'displayName' => $revision->editedBy->display_name,
                            'avatarUrl'   => $revision->editedBy->avatar_url,
                        ],
                    ])->values()->all();
                })
                // Array, não model, então a resposta é montada aqui: o
                // Endpoint::handle() só serializa sozinho o $context->model.
                ->response(fn ($data) => new JsonResponse([
                    'data' => [
                        'type'       => 'chat-message-revisions',
                        'attributes' => ['revisions' => $data],
                    ],
                ])),

            Endpoint\Endpoint::make('pin')
                ->route('POST', '/{id}/pin')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var Message $message */
                    $message = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->can('pin', $message)) {
                        throw new ForbiddenException();
                    }

                    // Toggle: sem rota de desafixar, e dois cliques rápidos não deixam
                    // estado pela metade.
                    if ($message->isPinned()) {
                        $message->pinned_at = null;
                        $message->pinned_by_id = null;
                    } else {
                        $message->pinned_at = Carbon::now();
                        $message->pinned_by_id = $actor->id;
                    }

                    $message->save();

                    $this->events->dispatch(new MessagePinToggled($message, $actor));

                    return $message;
                })
                ->defaultInclude(['user', 'user.groups', 'pinnedBy']),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('content')
                ->nullable()
                // Apagadas voltam como tombstone para o stream manter a forma; o texto
                // só vai para o autor e moderadores.
                ->get(fn (Message $m, Context $context) => $this->visibleContent($m, $context->getActor())),

            Schema\Str::make('contentHtml')
                ->nullable()
                ->get(function (Message $m, Context $context) {
                    if ($this->isRedacted($m, $context->getActor()) || $m->isSystem()) {
                        return null;
                    }

                    // O conteúdo passa pelos callbacks de formatter de toda extensão,
                    // feitos para posts. Se um lançar exceção, a mensagem cai para
                    // texto puro em vez de derrubar a página do canal.
                    try {
                        return $m->formatContent($context->request);
                    } catch (\Throwable $e) {
                        $this->log->error('[ramon/chat] formatContent failed for message '.$m->id.': '.$e->getMessage(), [
                            'exception' => $e,
                        ]);

                        return $m->fallbackContentHtml();
                    }
                }),

            Schema\Str::make('type'),
            Schema\Str::make('systemKey')->nullable(),
            Schema\Arr::make('systemData')->nullable(),

            Schema\Integer::make('number')->nullable(),
            Schema\Integer::make('channelId'),
            Schema\Integer::make('threadId')->nullable(),
            Schema\Integer::make('replyToId')->nullable(),

            Schema\DateTime::make('createdAt'),
            Schema\DateTime::make('editedAt')->nullable(),
            Schema\DateTime::make('deletedAt')->nullable(),

            // Cursor do polling de fallback. Toda mudança (edição, delete, fixar,
            // reação) avança a coluna, e o cliente pede "o que mudou desde" num
            // filtro só (ver Search\Filter\MessageChangedFilter).
            Schema\DateTime::make('updatedAt'),

            Schema\Boolean::make('isDeleted')
                ->get(fn (Message $m) => $m->isDeleted()),

            Schema\Boolean::make('isEdited')
                ->get(fn (Message $m) => $m->isEdited()),

            // Se outra pessoa que não o autor apagou. Texto retido não responde
            // isso. Lido da coluna porque o push realtime não traz `deletedBy`.
            Schema\Boolean::make('isModeratorDeleted')
                ->get(fn (Message $m) => $m->isDeleted()
                    && $m->deleted_by_id !== null
                    && (int) $m->deleted_by_id !== (int) $m->user_id),

            Schema\DateTime::make('pinnedAt')->nullable(),

            Schema\Boolean::make('isPinned')
                ->get(fn (Message $m) => $m->isPinned()),

            // Resumo de reações: emoji => { count, reacted }, já agregado para o
            // cliente não guardar cada linha.
            Schema\Arr::make('reactionSummary')
                ->get(fn (Message $m, Context $context) => $this->reactionSummary($m, $context->getActor())),

            Schema\Arr::make('mentionedUsers')
                ->get(fn (Message $m) => $m->mentions
                    ->where('type', 'user')
                    ->pluck('user_id')
                    ->filter()
                    ->values()
                    ->all()),

            Schema\Boolean::make('mentionsChannelWide')
                ->get(fn (Message $m) => $m->mentions->contains(fn ($mention) => $mention->isChannelWide())),

            // Rank antes do nome do autor: dono, moderador ou o maior rank dado.
            // Vem do rank book em cache do canal carregado, uma leitura por página.
            // Sem canal carregado (última mensagem, thread) não há linha de autor,
            // e carregar o canal seria uma query por linha.
            Schema\Arr::make('authorRank')
                ->nullable()
                ->get(function (Message $m) {
                    if ($m->user_id === null || ! $m->relationLoaded('channel')) {
                        return null;
                    }

                    $channel = $m->getRelation('channel');

                    return $channel instanceof Channel ? $this->ranks->forUser($channel, (int) $m->user_id) : null;
                }),

            Schema\Boolean::make('isBookmarked')
                ->get(function (Message $m, Context $context) {
                    $actor = $context->getActor();

                    if (! $actor->exists) {
                        return false;
                    }

                    return $m->bookmarks->contains(fn (Bookmark $b) => $b->user_id === $actor->id);
                }),

            // ── Flags de capacidade ──
            Schema\Boolean::make('canEdit')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('edit', $m)),

            Schema\Boolean::make('canDelete')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('delete', $m)),

            Schema\Boolean::make('canReact')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('react', $m)),

            Schema\Boolean::make('canReply')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('reply', $m)),

            Schema\Boolean::make('canCreateThread')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('createThread', $m)),

            Schema\Boolean::make('canMove')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('move', $m)),

            Schema\Boolean::make('canPin')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('pin', $m)),

            Schema\Boolean::make('canForceDelete')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('forceDelete', $m)),

            Schema\Boolean::make('canFlag')
                ->get(fn (Message $m, Context $context) => $context->getActor()->can('flag', $m)),

            // Se *este* ator já denunciou, para o botão mostrar "denunciada".
            Schema\Boolean::make('isFlagged')
                ->get(function (Message $m, Context $context) {
                    $actor = $context->getActor();

                    if (! $actor->exists) {
                        return false;
                    }

                    return $m->flags->contains(
                        fn ($flag) => $flag->user_id === $actor->id && $flag->resolved_at === null
                    );
                }),

            // Denúncias em aberto, só para moderadores: visível, convidaria a
            // engrossar o coro e avisaria o denunciado. Lido da relação carregada,
            // não contado por linha.
            Schema\Integer::make('flagsCount')
                ->visible(fn (Message $m, Context $context) => $context->getActor()->hasPermission('ramon-chat.moderate'))
                ->get(fn (Message $m) => $m->flags->whereNull('resolved_at')->count()),

            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
            Schema\Relationship\ToOne::make('editedBy')->type('users')->includable(),
            Schema\Relationship\ToOne::make('deletedBy')->type('users')->includable(),
            Schema\Relationship\ToOne::make('pinnedBy')->type('users')->includable(),
            Schema\Relationship\ToOne::make('replyTo')->type('chat-messages')->includable(),
            Schema\Relationship\ToOne::make('thread')->type('chat-threads')->includable(),
            Schema\Relationship\ToOne::make('channel')->type('chat-channels')->includable(),
            Schema\Relationship\ToMany::make('uploads')->type('chat-uploads')->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('id'),
            SortColumn::make('createdAt'),
            SortColumn::make('number'),
            // Fixadas mais recentes primeiro, para o painel.
            SortColumn::make('pinnedAt'),
        ];
    }


    /**
     * Texto de mensagem apagada só para autor e moderadores; a linha ainda é
     * serializada para o cliente desenhar a tombstone no lugar.
     */
    protected function isRedacted(Message $message, User $actor): bool
    {
        if (! $message->isDeleted()) {
            return false;
        }

        return ! ($actor->hasPermission('ramon-chat.moderate') || $actor->id === $message->user_id);
    }

    /**
     * Texto-fonte da revisão, ou texto puro se o unparse lançar. Mesma proteção do
     * `contentHtml`: um callback de extensão não pode dar 500 no histórico.
     */
    protected function revisionContent(MessageRevision $revision): ?string
    {
        // getAttribute() em vez de `->content`: mesmo getter, mas deixa claro que
        // o unparse (e seus callbacks) pode lançar.
        try {
            $content = $revision->getAttribute('content');

            return is_string($content) ? $content : null;
        } catch (\Throwable $e) {
            $this->log->error('[ramon/chat] unparse failed for revision '.$revision->id.': '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return strip_tags($revision->fallbackContentHtml());
        }
    }

    protected function visibleContent(Message $message, User $actor): ?string
    {
        if ($this->isRedacted($message, $actor)) {
            return null;
        }

        return $message->content;
    }

    /**
     * @return array<string, array{count: int, reacted: bool}>
     */
    protected function reactionSummary(Message $message, User $actor): array
    {
        $summary = [];

        foreach ($message->reactions as $reaction) {
            $emoji = $reaction->emoji;

            if (! isset($summary[$emoji])) {
                $summary[$emoji] = ['count' => 0, 'reacted' => false];
            }

            $summary[$emoji]['count']++;

            if ($actor->exists && $reaction->user_id === $actor->id) {
                $summary[$emoji]['reacted'] = true;
            }
        }

        return $summary;
    }

    protected function findChannel(Context $context, int $channelId): Channel
    {
        /** @var Channel|null $channel */
        $channel = Channel::query()
            ->whereVisibleTo($context->getActor())
            ->find($channelId);

        if ($channel === null) {
            throw new ValidationException([
                'channelId' => $this->translator->trans('ramon-chat.api.channel_not_found'),
            ]);
        }

        return $channel;
    }

    protected function findThread(Context $context, mixed $threadId, Channel $channel): ?Thread
    {
        if ($threadId === null || $threadId === '') {
            return null;
        }

        /** @var Thread|null $thread */
        $thread = Thread::query()
            ->whereVisibleTo($context->getActor())
            ->where('channel_id', $channel->id)
            ->find((int) $threadId);

        if ($thread === null) {
            throw new ValidationException([
                'threadId' => $this->translator->trans('ramon-chat.api.invalid_thread_target'),
            ]);
        }

        return $thread;
    }

    protected function findReplyTarget(Context $context, mixed $replyToId): ?Message
    {
        if ($replyToId === null || $replyToId === '') {
            return null;
        }

        /** @var Message|null $target */
        $target = Message::query()
            ->whereVisibleTo($context->getActor())
            ->find((int) $replyToId);

        if ($target === null) {
            throw new ValidationException([
                'replyToId' => $this->translator->trans('ramon-chat.api.invalid_reply_target'),
            ]);
        }

        return $target;
    }

    /**
     * Recusa reação nova além dos limites. Remover nunca é recusado, para quem
     * está no teto sempre poder desfazer.
     *
     * @throws ValidationException
     */
    protected function assertRoomForReaction(Message $message, User $actor, string $emoji): void
    {
        $existing = MessageReaction::query()->where('message_id', $message->id);

        $kinds = (clone $existing)->distinct()->pluck('emoji')->map(fn ($e) => strtolower((string) $e));

        if ($kinds->count() >= self::MAX_DISTINCT_REACTIONS && ! $kinds->contains(strtolower($emoji))) {
            throw new ValidationException([
                'emoji' => $this->translator->trans('ramon-chat.api.reaction_limit'),
            ]);
        }

        if ((clone $existing)->where('user_id', $actor->id)->count() >= self::MAX_REACTIONS_PER_USER) {
            throw new ValidationException([
                'emoji' => $this->translator->trans('ramon-chat.api.reaction_limit'),
            ]);
        }
    }

    /**
     * Só shortcode simples. Sem catálogo, porque emoji custom do Flamoji também
     * reage por shortcode; o abuso é contido pelo tamanho e pelos limites de
     * assertRoomForReaction().
     *
     * @throws ValidationException
     */
    protected function normaliseEmoji(string $emoji): string
    {
        // Aceita `:heart:` e `heart`; grava sem os dois-pontos.
        $emoji = trim($emoji, ": \t\n\r");

        if ($emoji === '' || ! preg_match('/\A[a-z0-9_+\-]{1,60}\z/i', $emoji)) {
            throw new ValidationException([
                'emoji' => $this->translator->trans('ramon-chat.api.invalid_reaction'),
            ]);
        }

        return $emoji;
    }

    /**
     * Recalcula os contadores de canal e thread após delete ou restore, onde
     * incrementar acumularia desvio.
     */
    protected function refreshContainers(Message $message): void
    {
        $channel = $message->channel;

        if ($channel !== null) {
            $channel->refreshMetadata()->save();
        }

        $thread = $message->thread;

        if ($thread !== null) {
            $thread->refreshMetadata()->save();
        }
    }
}
