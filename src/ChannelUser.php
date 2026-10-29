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
 * Associação de um usuário a um canal e seu estado de leitura.
 *
 * @property int $id
 * @property int $channel_id
 * @property int $user_id
 * @property bool $following
 * @property bool $is_moderator
 * @property Carbon|null $moderator_since
 * @property int $notification_level
 * @property bool $muted
 * @property int|null $last_read_message_id
 * @property int $unread_count
 * @property int $unread_mentions_count
 * @property Carbon|null $last_viewed_at
 * @property Carbon|null $joined_at
 * @property Carbon|null $left_at
 * @property-read Channel|null $channel
 * @property-read User|null $user
 */
class ChannelUser extends AbstractModel
{
    /** Notifica toda mensagem do canal. */
    public const LEVEL_ALWAYS = 2;

    /** Notifica só menções diretas. Padrão. */
    public const LEVEL_MENTIONS = 1;

    /** Nunca notifica. */
    public const LEVEL_NEVER = 0;

    protected $table = 'chat_channel_user';

    public $timestamps = true;

    protected $casts = [
        'channel_id'            => 'integer',
        'user_id'               => 'integer',
        'following'             => 'boolean',
        'hidden'                => 'boolean',
        'is_moderator'          => 'boolean',
        'moderator_since'       => 'datetime',
        'notification_level'    => 'integer',
        'muted'                 => 'boolean',
        'last_read_message_id'  => 'integer',
        'unread_count'          => 'integer',
        'unread_mentions_count' => 'integer',
        'last_viewed_at'        => 'datetime',
        'joined_at'             => 'datetime',
        'left_at'               => 'datetime',
    ];

    public static function levels(): array
    {
        return [self::LEVEL_NEVER, self::LEVEL_MENTIONS, self::LEVEL_ALWAYS];
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

    public function hasLeft(): bool
    {
        return $this->left_at !== null;
    }

    /**
     * Se o dono fez deste membro um moderador. O que isso concede fica em
     * Service\ChannelOwnership.
     */
    public function isModerator(): bool
    {
        return (bool) $this->is_moderator;
    }

    /**
     * Membro oculto: está no canal sem aparecer na lista nem na contagem.
     *
     * Usa getAttribute() porque `$this->hidden` dentro da classe é o array de
     * serialização do Eloquent, não a coluna de mesmo nome.
     */
    public function isHidden(): bool
    {
        return (bool) $this->getAttribute('hidden');
    }

    /**
     * Silenciar difere do nível 0: também tira o canal dos badges de não lidas,
     * enquanto o nível 0 só suprime notificações.
     */
    public function wantsNotificationFor(bool $isMention): bool
    {
        if ($this->muted) {
            return false;
        }

        return match ($this->notification_level) {
            self::LEVEL_ALWAYS   => true,
            self::LEVEL_MENTIONS => $isMention,
            default              => false,
        };
    }

    public function markReadUpTo(?int $messageId): static
    {
        $this->last_read_message_id = $messageId;
        $this->unread_count = 0;
        $this->unread_mentions_count = 0;
        $this->last_viewed_at = Carbon::now();

        return $this;
    }
}
