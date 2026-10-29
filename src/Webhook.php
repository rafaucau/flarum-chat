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
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Webhook de entrada que posta num canal. Aceita payloads no formato do Slack.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $username
 * @property string|null $emoji
 * @property int $channel_id
 * @property int|null $user_id
 * @property string $key
 * @property int|null $creator_id
 * @property bool $active
 * @property int $deliveries_count
 * @property Carbon|null $last_delivered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Channel|null $channel
 * @property-read User|null $creator
 * @property-read User|null $user
 */
class Webhook extends AbstractModel
{
    protected $table = 'chat_webhooks';

    /**
     * Injetado pelo ChatServiceProvider (ver Channel::$extensions sobre o static).
     */
    protected static ?UrlGenerator $url = null;

    public $timestamps = true;

    /**
     * A key autentica entregas; nunca pode chegar a um cliente não admin.
     */
    protected $hidden = ['key'];

    protected $casts = [
        'channel_id'        => 'integer',
        'user_id'           => 'integer',
        'creator_id'        => 'integer',
        'active'            => 'boolean',
        'deliveries_count'  => 'integer',
        'last_delivered_at' => 'datetime',
    ];

    public static function setUrlGenerator(UrlGenerator $url): void
    {
        static::$url = $url;
    }

    public static function build(string $name, Channel $channel, ?User $creator = null): static
    {
        $webhook = new static();

        $webhook->name = $name;
        $webhook->channel_id = $channel->id;
        $webhook->creator_id = $creator?->id;
        $webhook->key = static::generateKey();
        $webhook->active = true;

        return $webhook;
    }

    public static function generateKey(): string
    {
        do {
            $key = Str::random(48);
        } while (static::query()->where('key', $key)->exists());

        return $key;
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Conta usada nas entregas; null posta como o bot.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Autor da entrega, ou null para o bot. Só admin vale: uma conta rebaixada
     * não pode continuar postando por uma key que nunca teve.
     */
    public function author(): ?User
    {
        $user = $this->user;

        return $user !== null && $user->isAdmin() ? $user : null;
    }

    public function url(): string
    {
        return static::$url
            ->to('api')
            ->path('chat/hooks/'.$this->key);
    }

    public function recordDelivery(): static
    {
        $this->deliveries_count++;
        $this->last_delivered_at = Carbon::now();

        return $this;
    }
}
