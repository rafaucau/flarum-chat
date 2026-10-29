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
use Flarum\Foundation\EventGeneratorTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Subconversa pendurada numa mensagem do canal.
 *
 * @property int $id
 * @property int $channel_id
 * @property int|null $original_message_id
 * @property string|null $title
 * @property int|null $creator_id
 * @property string $status
 * @property int $replies_count
 * @property int|null $last_message_id
 * @property Carbon|null $last_message_at
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Channel|null $channel
 * @property-read Message|null $originalMessage
 * @property-read Message|null $lastMessage
 * @property-read User|null $creator
 * @property-read Collection<int, Message> $messages
 * @property-read ThreadUser|null $actorMembership
 */
class Thread extends AbstractModel
{
    use EventGeneratorTrait;
    use ScopeVisibilityTrait;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'chat_threads';

    public $timestamps = true;

    /**
     * Associações já consultadas, por user id. Por instância, não static: um
     * static sobreviveria entre atores num worker de fila.
     *
     * @var array<int, ThreadUser|null>
     */
    protected array $membershipCache = [];


    protected $casts = [
        'channel_id'          => 'integer',
        'original_message_id' => 'integer',
        'creator_id'          => 'integer',
        'replies_count'       => 'integer',
        'last_message_id'     => 'integer',
        'last_message_at'     => 'datetime',
        'deleted_at'          => 'datetime',
    ];

    public static function build(Channel $channel, Message $originalMessage, ?User $creator = null): static
    {
        $thread = new static();

        $thread->channel_id = $channel->id;
        $thread->original_message_id = $originalMessage->id;
        $thread->creator_id = $creator->id ?? $originalMessage->user_id;
        $thread->status = self::STATUS_OPEN;
        $thread->replies_count = 0;

        return $thread;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function acceptsMessages(): bool
    {
        return $this->isOpen() && ! $this->isDeleted();
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function originalMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'original_message_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }

    /**
     * @return HasMany<ThreadUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ThreadUser::class, 'thread_id');
    }

    /**
     * Associação de quem pede, para endpoints com eager load.
     *
     * Sem restrição aqui: o endpoint aplica `eagerLoadWhere`, pois só ele conhece o
     * ator. Lida direto seria a linha de qualquer membro; por isso só
     * `membershipFor()`, que confere o dono da linha, a usa. Mesmo padrão de Channel.
     */
    /**
     * @return HasOne<ThreadUser, $this>
     */
    public function actorMembership(): HasOne
    {
        return $this->hasOne(ThreadUser::class, 'thread_id');
    }

    /**
     * Memo por instância, inclusive ausências (`array_key_exists`, não `isset`).
     */
    public function membershipFor(?User $user): ?ThreadUser
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

            if ($loaded instanceof ThreadUser && (int) $loaded->user_id === $id) {
                return $this->membershipCache[$id] = $loaded;
            }
        }

        return $this->membershipCache[$id] = $this->memberships()
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Descarta o memo após entrar ou sair, para a próxima leitura na mesma request
     * ver a mudança. Null limpa tudo.
     */
    public function forgetMembership(?User $user = null): void
    {
        if ($user === null) {
            $this->membershipCache = [];

            return;
        }

        unset($this->membershipCache[(int) $user->id]);
    }

    /**
     * Registra uma resposta nova de forma incremental, sem refreshMetadata(): o
     * COUNT varre a thread inteira e deixaria as threads movimentadas mais lentas.
     */
    public function noteReply(Message $message): static
    {
        // A raiz não é resposta. Não deveria chegar aqui, mas a guarda mantém a
        // invariante local.
        if ($this->original_message_id !== null && (int) $message->id === (int) $this->original_message_id) {
            return $this;
        }

        $this->replies_count = (int) $this->replies_count + 1;
        $this->last_message_id = $message->id;
        $this->last_message_at = $message->created_at;

        return $this;
    }

    /**
     * Recalcula os contadores a partir das mensagens, para exclusão, restauração
     * ou movimentação. Respostas novas usam noteReply(). A raiz não conta.
     */
    public function refreshMetadata(): static
    {
        $replies = $this->messages()
            ->whereNull('deleted_at')
            ->when(
                $this->original_message_id !== null,
                fn ($q) => $q->whereKeyNot($this->original_message_id)
            );

        $last = (clone $replies)->orderByDesc('id')->first();

        $this->replies_count = $replies->count();
        $this->last_message_id = $last?->id;
        $this->last_message_at = $last?->created_at;

        return $this;
    }
}
