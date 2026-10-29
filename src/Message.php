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
use Flarum\Formatter\Formattable;
use Flarum\Formatter\HasFormattedContent;
use Flarum\Foundation\EventGeneratorTrait;
use Flarum\Post\Post;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Expression;
use Ramon\Chat\Formatter\ResolvesMentionedPosts;

/**
 * @property int $id
 * @property int $channel_id
 * @property int|null $user_id
 * @property int|null $thread_id
 * @property int|\Illuminate\Database\Query\Expression|null $number
 * @property int|null $reply_to_id
 * @property string|null $content
 * @property string|null $parsed_content
 * @property string $type
 * @property string|null $system_key
 * @property array|null $system_data
 * @property int|null $webhook_id
 * @property Carbon|null $edited_at
 * @property int|null $edited_by_id
 * @property Carbon|null $deleted_at
 * @property int|null $deleted_by_id
 * @property Carbon|null $pinned_at
 * @property int|null $pinned_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Channel|null $channel
 * @property-read User|null $user
 * @property-read Thread|null $thread
 * @property-read Message|null $replyTo
 * @property-read Collection<int, MessageReaction> $reactions
 * @property-read Collection<int, Upload> $uploads
 * @property-read Collection<int, MessageMention> $mentions
 * @property-read Collection<int, MessageRevision> $revisions
 * @property-read Collection<int, Post> $mentionsPosts
 */
class Message extends AbstractModel implements Formattable
{
    use EventGeneratorTrait;
    use ScopeVisibilityTrait;
    use HasFormattedContent;
    use ResolvesMentionedPosts;

    public const TYPE_TEXT = 'text';

    /**
     * Gerada pelo sistema (entradas, saídas, status do canal). Renderizada por
     * chave de locale, nunca a partir de texto do usuário.
     */
    public const TYPE_SYSTEM = 'system';

    /**
     * Postada pelo bot do chat. Ao contrário de TYPE_SYSTEM, é uma mensagem comum
     * com conteúdo formatado. Tem `user_id = null` e o cliente tira nome e avatar
     * das configurações, então não existe conta do bot para logar ou personificar.
     */
    public const TYPE_BOT = 'bot';

    protected $table = 'chat_messages';

    public $timestamps = true;

    protected $casts = [
        'channel_id'     => 'integer',
        'user_id'        => 'integer',
        'thread_id'      => 'integer',
        'number'         => 'integer',
        'reply_to_id'    => 'integer',
        'webhook_id'     => 'integer',
        'edited_by_id'   => 'integer',
        'deleted_by_id'  => 'integer',
        'pinned_by_id'   => 'integer',
        'system_data'    => 'array',
        'edited_at'      => 'datetime',
        'deleted_at'     => 'datetime',
        'pinned_at'      => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();

        // Sequência por canal calculada por subquery no próprio INSERT, para envios
        // concorrentes não lerem o mesmo MAX e colidirem no índice único.
        static::creating(function (self $message) {
            if ($message->number === null && $message->channel_id !== null) {
                $query = static::getConnectionResolver()->connection()->table('chat_messages', 'cm');

                // Expression não carrega bindings, então o id do canal entra como
                // Expression com cast para int (não carrega input) e a coluna é
                // envolvida pela grammar, que aplica prefixo e quoting.
                $next = new Expression('COALESCE(MAX('.$query->getGrammar()->wrap('cm.number').'), 0) + 1');

                $message->number = new Expression('('.
                    $query
                        ->where('cm.channel_id', '=', new Expression((string) (int) $message->channel_id))
                        ->select($next)
                        ->toSql()
                    .')');
            }
        });

        // A Expression continua no model após o insert; relê o valor real. Usa o
        // atributo cru porque o cast `integer` transformaria a Expression em 1.
        static::created(function (self $message) {
            $raw = $message->getAttributes()['number'] ?? null;

            if ($raw instanceof Expression) {
                $message->number = (int) static::getConnectionResolver()
                    ->connection()
                    ->table('chat_messages')
                    ->where('id', $message->id)
                    ->value('number');

                $message->syncOriginalAttribute('number');
            }
        });
    }

    public static function build(
        Channel $channel,
        ?User $user,
        string $content,
        ?Thread $thread = null,
        ?Message $replyTo = null,
        ?User $parseAs = null
    ): static {
        $message = new static();

        $message->channel_id = $channel->id;
        $message->user_id = $user?->id;
        $message->thread_id = $thread?->id;
        $message->reply_to_id = $replyTo?->id;
        $message->type = self::TYPE_TEXT;
        $message->created_at = Carbon::now();

        // O formatter processa como o autor (menções, permissões), salvo quando o
        // texto é de outro (anúncio, webhook): aí usa `$parseAs`.
        $message->setContentAttribute($content, $parseAs ?? $user);

        return $message;
    }

    public static function buildSystem(Channel $channel, string $key, array $data = []): static
    {
        $message = new static();

        $message->channel_id = $channel->id;
        $message->type = self::TYPE_SYSTEM;
        $message->system_key = $key;
        $message->system_data = $data;
        $message->created_at = Carbon::now();

        return $message;
    }

    /**
     * Mensagem do bot, sem autor humano, pelo mesmo formatter.
     *
     * Processada como Guest para não herdar permissões de quem disparou o post.
     * Não null: flarum/mentions lê null como "sem restrição" e linkaria posts e
     * grupos ocultos.
     *
     * @param  array<string, mixed>  $data  Dados estruturados, para a mensagem continuar consultável.
     */
    public static function buildBot(Channel $channel, ?string $key, string $content, array $data = []): static
    {
        $message = new static();

        $message->channel_id = $channel->id;
        $message->user_id = null;
        $message->type = self::TYPE_BOT;
        $message->system_key = $key;
        $message->system_data = $data;
        $message->created_at = Carbon::now();

        $message->setContentAttribute($content, new Guest());

        return $message;
    }

    public function isBot(): bool
    {
        return $this->type === self::TYPE_BOT;
    }

    public function isSystem(): bool
    {
        return $this->type === self::TYPE_SYSTEM;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Só `pinned_at` decide; `pinned_by_id` é atribuição e é zerado junto.
     */
    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isInThread(): bool
    {
        return $this->thread_id !== null;
    }

    /**
     * Mensagem raiz de uma thread.
     */
    public function isThreadRoot(): bool
    {
        return $this->thread_id !== null
            && $this->thread !== null
            && $this->thread->original_message_id === $this->id;
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_id');
    }

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    /** @return BelongsTo<self, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /** @return BelongsTo<Webhook, $this> */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class, 'webhook_id');
    }

    /** @return HasMany<MessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class, 'message_id');
    }

    /** @return HasMany<Upload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class, 'message_id');
    }

    /** @return HasMany<MessageMention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(MessageMention::class, 'message_id');
    }

    /** @return HasMany<MessageRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(MessageRevision::class, 'message_id');
    }

    /** @return HasMany<Bookmark, $this> */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class, 'message_id');
    }

    /** @return HasMany<MessageFlag, $this> */
    public function flags(): HasMany
    {
        return $this->hasMany(MessageFlag::class, 'message_id');
    }

    /**
     * Grava o conteúdo anterior como revisão e aplica o novo.
     */
    public function reviseContent(string $content, User $actor): static
    {
        $previous = $this->attributes['content'] ?? null;

        if ($previous !== null) {
            $revision = new MessageRevision();
            $revision->message_id = $this->id;
            $revision->setParsedContentAttribute($previous);
            $revision->edited_by_id = $actor->id;
            $revision->created_at = Carbon::now();
            $revision->save();
        }

        $this->setContentAttribute($content, $this->user ?? $actor);
        $this->edited_at = Carbon::now();
        $this->edited_by_id = $actor->id;

        return $this;
    }
}
