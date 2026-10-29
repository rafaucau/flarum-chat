<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Config;
use Flarum\Foundation\EventGeneratorTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $type
 * @property string|null $name
 * @property string|null $slug
 * @property string|null $description
 * @property string|null $emoji
 * @property string|null $image_path
 * @property int|null $tag_id
 * @property string $status
 * @property bool $is_private
 * @property string $post_permission
 * @property bool $threading_enabled
 * @property int $slow_mode_seconds
 * @property int|null $max_message_length
 * @property bool $auto_join
 * @property bool $auto_join_on_reply
 * @property bool $post_discussions
 * @property bool $allow_channel_wide_mentions
 * @property int|null $creator_id
 * @property int $messages_count
 * @property int $user_count
 * @property int|null $last_message_id
 * @property Carbon|null $last_message_at
 * @property int|null $archived_discussion_id
 * @property Carbon|null $archived_at
 * @property int|null $archived_by_id
 * @property Carbon|null $deleted_at
 * @property int|null $deleted_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $creator
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, Thread> $threads
 * @property-read Collection<int, User> $participants
 * @property-read Message|null $lastMessage
 */
class Channel extends AbstractModel
{
    use EventGeneratorTrait;
    use ScopeVisibilityTrait;

    /**
     * Canal ligado a uma tag (ou ao fórum todo sem tags). Herda a visibilidade
     * da tag, sem uma segunda camada de permissões.
     */
    public const TYPE_CATEGORY = 'category';

    /**
     * Conversa 1:1 ou em grupo, com lista explícita de participantes.
     */
    public const TYPE_DIRECT = 'direct';

    /** Teto de participantes de uma conversa direta, quem a abriu incluído. */
    public const MAX_DIRECT_PARTICIPANTS = 20;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'chat_channels';

    /**
     * Serviços injetados pelo ChatServiceProvider. Não guardam estado de ator nem
     * de request, então o static é seguro num worker.
     */
    protected static ?ExtensionManager $extensions = null;

    protected static ?Config $config = null;

    public $timestamps = true;

    /**
     * Associações já consultadas, por user id. Por instância, não static: um
     * static sobreviveria entre atores num worker de fila.
     *
     * @var array<int, ChannelUser|null>
     */
    protected array $membershipCache = [];

    /**
     * Convites pendentes já consultados, por user id.
     *
     * @var array<int, ChannelInvite|null>
     */
    protected array $inviteCache = [];

    /**
     * Saídas já consultadas, por user id, para `hasLeft()`.
     *
     * @var array<int, bool>
     */
    protected array $departureCache = [];

    protected $casts = [
        'tag_id'                      => 'integer',
        'creator_id'                  => 'integer',
        'messages_count'              => 'integer',
        'user_count'                  => 'integer',
        'last_message_id'             => 'integer',
        'archived_discussion_id'      => 'integer',
        'archived_by_id'              => 'integer',
        'deleted_by_id'               => 'integer',
        'slow_mode_seconds'           => 'integer',
        'max_message_length'          => 'integer',
        'is_private'                  => 'boolean',
        'threading_enabled'           => 'boolean',
        'auto_join'                   => 'boolean',
        'auto_join_on_reply'          => 'boolean',
        'post_discussions'            => 'boolean',
        'allow_channel_wide_mentions' => 'boolean',
        'last_message_at'             => 'datetime',
        'archived_at'                 => 'datetime',
        'deleted_at'                  => 'datetime',
    ];

    public static function setServices(ExtensionManager $extensions, Config $config): void
    {
        static::$extensions = $extensions;
        static::$config = $config;
    }

    public static function build(
        string $type,
        ?string $name = null,
        ?string $description = null,
        ?int $tagId = null,
        ?User $creator = null
    ): static {
        $channel = new static();

        $channel->type = $type;
        $channel->name = $name;
        $channel->description = $description;
        $channel->tag_id = $tagId;
        $channel->creator_id = $creator?->id;
        $channel->status = self::STATUS_OPEN;

        if ($type === self::TYPE_CATEGORY && $name !== null) {
            $channel->slug = $channel->generateSlug($name);
        }

        return $channel;
    }

    /**
     * Só canais de categoria usam slug; diretos são endereçados por id.
     */
    public function generateSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'channel';
        $slug = $base;
        $suffix = 1;

        while (
            static::query()
                ->where('slug', $slug)
                ->when($this->exists, fn (Builder $q) => $q->whereKeyNot($this->id))
                ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    public const POST_ALL = 'all';
    /** Só quem tem `ramon-chat.moderate` posta. */
    public const POST_MODERATORS = 'moderators';

    /**
     * Canal de anúncios: só moderadores postam. Nunca vale para canal direto.
     */
    public function restrictsPostingToModerators(): bool
    {
        return $this->isCategory() && $this->post_permission === self::POST_MODERATORS;
    }

    /**
     * Só por convite: fora do Browse e só membros veem. Canal direto nunca recebe
     * a flag; já é privado por natureza.
     */
    public function isPrivate(): bool
    {
        return $this->isCategory() && (bool) $this->is_private;
    }

    public function isCategory(): bool
    {
        return $this->type === self::TYPE_CATEGORY;
    }

    /**
     * Todo mundo que vê o canal está nele ("adicionar todos").
     *
     * Não cria linha de associação antecipada: ela só surge ao ler, mudar a
     * notificação ou sair. Uma linha por conta não escala em fóruns grandes.
     * Nunca vale para canal privado.
     */
    public function isForEveryone(): bool
    {
        return $this->isCategory() && (bool) $this->auto_join && ! $this->isPrivate();
    }

    /**
     * Versão SQL de isForEveryone(); as duas precisam ficar em sincronia.
     */
    public static function onlyForEveryone(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->where('chat_channels.type', self::TYPE_CATEGORY)
            ->where('chat_channels.auto_join', true)
            ->where('chat_channels.is_private', false);
    }

    /**
     * Ids dos canais para todos, como subquery.
     */
    public static function idsForEveryone(): \Closure
    {
        return fn (QueryBuilder $sub) => static::onlyForEveryone($sub->select('chat_channels.id')->from('chat_channels'));
    }

    /**
     * Se o usuário está no canal: associação ativa ou, em canal para todos, nunca
     * ter saído. Não checa visibilidade; quem chama já garantiu.
     */
    public function includes(?User $user): bool
    {
        if ($this->membershipFor($user) !== null) {
            return true;
        }

        if ($user === null || ! $user->exists || ! $this->isForEveryone()) {
            return false;
        }

        return ! $this->hasLeft($user);
    }

    /**
     * Se o usuário saiu, com memo como `membershipFor()`. Em canal para todos,
     * essa linha é o que o mantém fora.
     */
    public function hasLeft(User $user): bool
    {
        $id = (int) $user->id;

        if (! array_key_exists($id, $this->departureCache)) {
            $this->departureCache[$id] = ChannelUser::query()
                ->where('channel_id', $this->id)
                ->where('user_id', $id)
                ->whereNotNull('left_at')
                ->exists();
        }

        return $this->departureCache[$id];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Canais fechados e arquivados continuam legíveis, mas congelados.
     */
    public function acceptsMessages(): bool
    {
        return $this->isOpen() && ! $this->isDeleted();
    }

    /**
     * Tamanho máximo de mensagem. Null e zero seguem o fórum; o canal só reduz,
     * nunca passa do teto do fórum.
     */
    public function maxMessageLength(int $forumDefault): int
    {
        return $this->max_message_length ? min($this->max_message_length, $forumDefault) : $forumDefault;
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Se o usuário criou o canal; o que isso concede fica em
     * Service\ChannelOwnership. Canais diretos nunca contam.
     */
    public function isOwnedBy(?User $user): bool
    {
        if ($user === null || $user->isGuest() || $this->creator_id === null) {
            return false;
        }

        return $this->isCategory() && (int) $this->creator_id === (int) $user->id;
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'channel_id');
    }

    /** @return HasMany<Thread, $this> */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class, 'channel_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    /** @return HasMany<Webhook, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class, 'channel_id');
    }

    /**
     * Membros como os outros os veem; ocultos ficam de fora.
     *
     * Em canal para todos, só criador e moderadores (o resto nem tem linha). A
     * restrição fica no SQL para valer também em eager loads.
     *
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_channel_user', 'channel_id', 'user_id')
            ->withPivot('is_moderator')
            ->whereNull('chat_channel_user.left_at')
            ->where('chat_channel_user.hidden', false)
            ->where(function ($query) {
                $query->whereNotIn('chat_channel_user.channel_id', static::idsForEveryone())
                    ->orWhere('chat_channel_user.is_moderator', true)
                    ->orWhereIn('chat_channel_user.user_id', function (QueryBuilder $sub) {
                        $sub->select('owned.creator_id')
                            ->from('chat_channels', 'owned')
                            ->whereColumn('owned.id', 'chat_channel_user.channel_id');
                    });
            });
    }

    /**
     * Mesmo conjunto de `participants`, para rotular canais diretos. Relação à
     * parte para restringir o eager load (`eagerLoadWhere`) só aos diretos sem
     * esvaziar `?include=participants`.
     */
    public function directParticipants(): BelongsToMany
    {
        return $this->participants();
    }

    /** Quem está na conversa agora, contando os membros ocultos. */
    public function activeParticipantCount(): int
    {
        return $this->memberships()->whereNull('left_at')->count();
    }

    /**
     * Toda linha de associação, inclusive ocultos e silenciados.
     *
     * @return HasMany<ChannelUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ChannelUser::class, 'channel_id');
    }

    /** @return HasMany<ChannelInvite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(ChannelInvite::class, 'channel_id');
    }

    /**
     * Convidados sem resposta, para a aba de membros de quem gerencia.
     *
     * @return BelongsToMany<User, $this>
     */
    public function invitedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_channel_invites', 'channel_id', 'user_id')
            ->withPivot('inviter_id');
    }

    /**
     * Convite de quem pede. Sem restrição aqui, como `actorMembership()`: o
     * endpoint restringe o eager load. Só `pendingInviteFor()` deve lê-la.
     *
     * @return HasOne<ChannelInvite, $this>
     */
    public function actorInvite(): HasOne
    {
        return $this->hasOne(ChannelInvite::class, 'channel_id');
    }

    /**
     * Convite pendente, com memo como `membershipFor()`. Um `actorInvite` já
     * carregado responde sem query, mesmo vazio: ele só é carregado restrito ao
     * ator da request.
     */
    public function pendingInviteFor(?User $user): ?ChannelInvite
    {
        if ($user === null || ! $user->exists) {
            return null;
        }

        $id = (int) $user->id;

        if (array_key_exists($id, $this->inviteCache)) {
            return $this->inviteCache[$id];
        }

        if ($this->relationLoaded('actorInvite')) {
            $loaded = $this->getRelation('actorInvite');

            if ($loaded === null) {
                return $this->inviteCache[$id] = null;
            }

            if ($loaded instanceof ChannelInvite && (int) $loaded->user_id === $id) {
                return $this->inviteCache[$id] = $loaded;
            }
        }

        /** @var ChannelInvite|null $invite */
        $invite = $this->invites()
            ->where('user_id', $id)
            ->first();

        return $this->inviteCache[$id] = $invite;
    }

    public function forgetInvite(?User $user = null): void
    {
        if ($user === null) {
            $this->inviteCache = [];

            return;
        }

        unset($this->inviteCache[(int) $user->id]);
    }

    /**
     * Tag de onde vêm as permissões, resolvida sem depender de flarum/tags.
     * Checa se a extensão está *ativa*, não se a classe existe: sem as
     * migrations de tags a relação daria 500.
     */
    public function tag(): ?BelongsTo
    {
        if (! static::$extensions?->isEnabled('flarum-tags')) {
            return null;
        }

        return $this->belongsTo(\Flarum\Tags\Tag::class, 'tag_id');
    }

    /**
     * Associação de quem pede, para endpoints com eager load.
     *
     * Sem restrição aqui: o endpoint aplica `eagerLoadWhere`, pois só ele conhece o
     * ator. Lida direto seria a linha de qualquer membro; por isso só
     * `membershipFor()`, que confere o dono da linha, a usa.
     *
     * @return HasOne<ChannelUser, $this>
     */
    public function actorMembership(): HasOne
    {
        return $this->hasOne(ChannelUser::class, 'channel_id');
    }

    /**
     * Memo por instância, inclusive ausências (`array_key_exists`, não `isset`).
     * A ChannelPolicy consulta isto várias vezes por canal da sidebar.
     */
    public function membershipFor(?User $user): ?ChannelUser
    {
        if ($user === null || ! $user->exists) {
            return null;
        }

        $id = (int) $user->id;

        if (array_key_exists($id, $this->membershipCache)) {
            return $this->membershipCache[$id];
        }

        // Usa a linha do eager load só se for deste usuário. Null é ambíguo (não
        // membro ou relação restrita a outro), então cai na query.
        if ($this->relationLoaded('actorMembership')) {
            $loaded = $this->getRelation('actorMembership');

            if ($loaded instanceof ChannelUser && (int) $loaded->user_id === $id) {
                return $this->membershipCache[$id] = $loaded;
            }
        }

        return $this->membershipCache[$id] = $this->memberships()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();
    }

    /**
     * Descarta o memo após entrar, sair, adicionar ou remover, para a próxima
     * leitura na mesma request ver a mudança. Null limpa tudo.
     */
    public function forgetMembership(?User $user = null): void
    {
        if ($user === null) {
            $this->membershipCache = [];
            $this->departureCache = [];

            return;
        }

        unset($this->membershipCache[(int) $user->id], $this->departureCache[(int) $user->id]);
    }

    /**
     * Preenche o memo de membershipFor() e pendingInviteFor() para vários
     * usuários com uma query cada, inclusive ausências.
     *
     * @param  int[]  $userIds
     */
    public function rememberLookupsFor(array $userIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === [] || ! $this->exists) {
            return;
        }

        $memberships = $this->memberships()
            ->whereIn('user_id', $ids)
            ->whereNull('left_at')
            ->get()
            ->keyBy('user_id');

        $invites = $this->invites()
            ->whereIn('user_id', $ids)
            ->get()
            ->keyBy('user_id');

        foreach ($ids as $id) {
            $this->membershipCache[$id] = $memberships->get($id);
            $this->inviteCache[$id] = $invites->get($id);
        }
    }

    /**
     * Imagem do canal, ou null. Montada a partir da base de assets, como o logo
     * do core, para sobreviver a uma troca de host.
     */
    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return static::$config->url()->getPath()
            .'/assets/'.$this->image_path;
    }

    /**
     * Recalcula os contadores após operações em lote (mover, podar, arquivar).
     * `user_count` conta só membros visíveis, como o MembershipManager.
     */
    public function refreshMetadata(): static
    {
        $last = $this->messages()
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $this->messages_count = $this->messages()->whereNull('deleted_at')->count();
        $this->last_message_id = $last?->id;
        $this->last_message_at = $last?->created_at;
        $this->user_count = $this->memberships()
            ->whereNull('left_at')
            ->where('hidden', false)
            ->count();

        return $this;
    }
}
