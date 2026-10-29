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
use Flarum\Group\Group;
use Flarum\Locale\Translator;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Ramon\Chat\Event\FlagsChanged;
use Ramon\Chat\Message;
use Ramon\Chat\MessageFlag;
use Ramon\Chat\Notification\MessageFlaggedBlueprint;
use Ramon\Chat\Service\ActionThrottle;
use Tobyz\JsonApiServer\Context as OriginalContext;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * Fila de moderação própria do chat. O flarum/flags não serve: `flags.post_id` é
 * FK não nula para `posts`, e alterar o schema de outra extensão seria desfeito na
 * próxima versão dela.
 *
 * @extends AbstractDatabaseResource<MessageFlag>
 */
class MessageFlagResource extends AbstractDatabaseResource
{
    /** Denúncias que alguém fora da moderação registra por hora. */
    public const FLAGS_PER_HOUR = 20;

    public function __construct(
        protected Translator $translator,
        protected NotificationSyncer $notifications,
        protected Events $events,
        protected ActionThrottle $throttle
    ) {
    }

    public function type(): string
    {
        return 'chat-message-flags';
    }

    public function model(): string
    {
        return MessageFlag::class;
    }

    /**
     * Denúncias só para moderadores, e só de mensagens que eles já veem. Os
     * endpoints também conferem a permissão; o scope é a segunda camada, para
     * qualquer outro caminho até a query.
     */
    public function scope(Builder $query, OriginalContext $context): void
    {
        $actor = $context->getActor();

        if (! $actor->hasPermission('ramon-chat.moderate')) {
            $query->whereRaw('1 = 0');

            return;
        }

        // A fila herda a visibilidade das mensagens (ScopeChannelVisibility) e
        // nunca dá *mais* do que ler o canal daria. `whereVisibleTo` é scope do
        // ScopeVisibilityTrait, invisível para a análise estática.
        // @phpstan-ignore method.notFound (Flarum model scope)
        $query->whereHas('message', fn (Builder $q) => $q->whereVisibleTo($actor));

        // Sem default aberta/resolvida: este scope também guarda o `Show`, e
        // esconder a resolvida daria 404 para uma linha que existe. A listagem
        // escolhe pelo `filter[resolved]`.
    }

    /**
     * O que cada mensagem denunciada precisa carregado antes de serializar. São
     * relações lidas dentro de getters e policies do MessageResource, que o
     * EloquentBuffer não cobre; sem isto seriam ~7 queries por denúncia, 50 por
     * página. O prefixo `message.` as repassa via `getEagerLoadsFor('message')`.
     *
     * `message` vem antes, filtrado pelo scope de visibilidade: `loadMissing()`
     * num path aninhado carrega o primeiro segmento sem restrição, e o buffer pula
     * relação já carregada, o que furaria o scope do MessageResource.
     */
    protected const EAGER_LOAD = [
        'message.user.groups',
        'message.channel',
        'message.reactions',
        'message.uploads',
        'message.mentions',
        'message.flags',
    ];

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->can('ramon-chat.moderate')
                ->defaultSort('-createdAt')
                ->defaultInclude(['user', 'message', 'message.user', 'message.channel', 'resolvedBy'])
                ->eagerLoad(self::EAGER_LOAD)
                ->eagerLoadWhere('message', fn ($query, Context $context) => $query->whereVisibleTo($context->getActor()))
                ->eagerLoadWhere(
                    'message.bookmarks',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                )
                ->paginate(50),

            Endpoint\Show::make()
                ->authenticated()
                ->can('ramon-chat.moderate')
                ->defaultInclude(['user', 'message', 'message.user', 'message.channel', 'resolvedBy'])
                ->eagerLoad(self::EAGER_LOAD)
                ->eagerLoadWhere('message', fn ($query, Context $context) => $query->whereVisibleTo($context->getActor()))
                ->eagerLoadWhere(
                    'message.bookmarks',
                    fn ($query, Context $context) => $query->where('user_id', $context->getActor()->id)
                ),

            // Registrar denúncia. Não é um Create simples: a mensagem é resolvida e
            // autorizada antes de gravar, e quem denuncia é o ator, não o body.
            Endpoint\Create::make()
                ->authenticated()
                ->action(function (Context $context) {
                    $attributes = Arr::get($context->body(), 'data.attributes', []);
                    $actor = $context->getActor();

                    $message = Message::whereVisibleTo($actor)
                        ->whereKey((int) Arr::get($attributes, 'messageId'))
                        ->first();

                    if (! $message instanceof Message) {
                        throw new ForbiddenException();
                    }

                    if (! $actor->can('flag', $message)) {
                        throw new ForbiddenException();
                    }

                    $reason = (string) Arr::get($attributes, 'reason', '');

                    if (! MessageFlag::isValidReason($reason)) {
                        throw new ValidationException([
                            'reason' => $this->translator->trans('ramon-chat.api.flag_reason_invalid'),
                        ]);
                    }

                    $detail = trim((string) Arr::get($attributes, 'detail', ''));

                    // `other` não diz nada sozinho, então só ele exige explicação;
                    // campo obrigatório nos outros desestimularia a denúncia.
                    if ($reason === 'other' && $detail === '') {
                        throw new ValidationException([
                            'detail' => $this->translator->trans('ramon-chat.api.flag_detail_required'),
                        ]);
                    }

                    // Cada denúncia (ou reabertura de uma resolvida) avisa a
                    // moderação; sem limite, desfazer a decisão viraria um loop.
                    if (! $actor->hasPermission('ramon-chat.moderate')
                        && ! $this->throttle->attempt('flag.'.$actor->id, self::FLAGS_PER_HOUR, 3600)) {
                        throw new ValidationException([
                            'reason' => $this->translator->trans('ramon-chat.api.action_rate_limited'),
                        ]);
                    }

                    /** @var MessageFlag|null $existing */
                    $existing = MessageFlag::query()
                        ->where('message_id', $message->id)
                        ->where('user_id', $actor->id)
                        ->first();

                    // Denunciar de novo atualiza a primeira em vez de bater no índice
                    // único: corrige o motivo e reabre uma denúncia já resolvida.
                    $flag = $existing ?? new MessageFlag();

                    $flag->message_id = $message->id;
                    $flag->user_id = $actor->id;
                    $flag->reason = $reason;
                    $flag->detail = $detail === '' ? null : mb_substr($detail, 0, 1000);
                    $flag->resolved_at = null;
                    $flag->resolved_by_id = null;
                    $flag->created_at = Carbon::now();
                    $flag->save();

                    $this->notifyAdministrators($flag, $message, $actor);

                    $this->events->dispatch(new FlagsChanged($actor, [(int) $message->id]));

                    return $flag;
                })
                ->defaultInclude(['user', 'message']),

            // Fecha a denúncia sem mexer na mensagem. Apagar a mensagem é outro
            // endpoint, e o listener fecha as denúncias dela.
            Endpoint\Endpoint::make('resolve')
                ->route('POST', '/{id}/resolve')
                ->authenticated()
                ->action(function (Context $context) {
                    /** @var MessageFlag $flag */
                    $flag = $context->model;
                    $actor = $context->getActor();

                    if (! $actor->hasPermission('ramon-chat.moderate')) {
                        throw new ForbiddenException();
                    }

                    $flag->resolve($actor)->save();

                    $this->events->dispatch(new FlagsChanged($actor, [(int) $flag->message_id]));

                    return $flag;
                })
                ->defaultInclude(['user', 'message', 'resolvedBy']),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('reason'),

            // Texto livre de quem denunciou: o cliente exibe como texto, nunca HTML
            // (ver FlaggedMessagesList).
            Schema\Str::make('detail')->nullable(),

            Schema\Integer::make('messageId'),

            Schema\DateTime::make('createdAt')->nullable(),
            Schema\DateTime::make('resolvedAt')->nullable(),

            Schema\Boolean::make('isResolved')
                ->get(fn (MessageFlag $flag) => $flag->isResolved()),

            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
            Schema\Relationship\ToOne::make('message')->type('chat-messages')->includable(),
            Schema\Relationship\ToOne::make('resolvedBy')->type('users')->includable(),
        ];
    }

    /**
     * Avisa os admins de uma denúncia nova. Só admins: `ramon-chat.moderate` pode
     * estar com um grupo grande, que acha a denúncia na fila e no badge.
     *
     * Os candidatos vêm do SQL; percorrer todos os usuários seria uma query por
     * conta. Quem denunciou fica de fora.
     */
    protected function notifyAdministrators(MessageFlag $flag, Message $message, User $reporter): void
    {
        // Em instruções separadas, não encadeado: `whereExists` é tipado como query
        // builder base, e a cadeia devolveria linhas cruas em vez de User.
        $query = User::query();
        $query->where('users.id', '!=', $reporter->id);
        $query->whereExists(function ($sub) {
            $sub->selectRaw(1)
                ->from('group_user')
                ->whereColumn('group_user.user_id', 'users.id')
                ->where('group_user.group_id', Group::ADMINISTRATOR_ID);
        });

        $recipients = $query
            ->get()
            // Admin tem toda permissão, mas canal com tag herda o `viewForum` da
            // categoria. Citar o canal a quem não o abre vazaria a sala.
            ->filter(fn (User $user) => $user->can('view', $message))
            ->values()
            ->all();

        if ($recipients === []) {
            return;
        }

        $this->notifications->sync(
            new MessageFlaggedBlueprint($flag, $message, $reporter),
            $recipients
        );
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('id'),
            SortColumn::make('createdAt'),
        ];
    }
}
