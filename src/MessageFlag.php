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
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Denúncia contra uma mensagem.
 *
 * @property int $id
 * @property int $message_id
 * @property int|null $user_id
 * @property string $reason
 * @property string|null $detail
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_id
 * @property Carbon|null $created_at
 * @property-read Message|null $message
 * @property-read User|null $user
 * @property-read User|null $resolvedBy
 */
class MessageFlag extends AbstractModel
{
    /**
     * Motivos aceitos, validados no servidor: conjunto fechado facilita a triagem.
     * Só `other` exige o campo de detalhe.
     */
    public const REASONS = ['spam', 'inappropriate', 'harassment', 'off_topic', 'other'];

    protected $table = 'chat_message_flags';

    public $timestamps = false;

    protected $casts = [
        'message_id'     => 'integer',
        'user_id'        => 'integer',
        'resolved_by_id' => 'integer',
        'created_at'     => 'datetime',
        'resolved_at'    => 'datetime',
    ];

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function resolve(User $actor): static
    {
        $this->resolved_at = Carbon::now();
        $this->resolved_by_id = $actor->id;

        return $this;
    }

    public static function isValidReason(string $reason): bool
    {
        return in_array($reason, self::REASONS, true);
    }
}
