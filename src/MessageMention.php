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
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Menção resolvida no envio. Persistida para que notificações e contagens de
 * menções não reprocessem o conteúdo.
 *
 * @property int $id
 * @property int $message_id
 * @property string $type
 * @property int|null $user_id
 * @property int|null $group_id
 * @property Carbon|null $created_at
 * @property-read Message|null $message
 * @property-read User|null $user
 * @property-read Group|null $group
 */
class MessageMention extends AbstractModel
{
    public const TYPE_USER = 'user';
    public const TYPE_GROUP = 'group';

    /** Todos presentes no canal agora. */
    public const TYPE_HERE = 'here';

    /** Todos os membros, presentes ou não. */
    public const TYPE_ALL = 'all';

    protected $table = 'chat_message_mentions';

    public $timestamps = false;

    protected $casts = [
        'message_id' => 'integer',
        'user_id'    => 'integer',
        'group_id'   => 'integer',
        'created_at' => 'datetime',
    ];

    public function isChannelWide(): bool
    {
        return in_array($this->type, [self::TYPE_HERE, self::TYPE_ALL], true);
    }

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

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}
