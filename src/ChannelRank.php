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
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cargo de um canal. Rank\RankBook transforma cargos no que aparece ao lado
 * de cada nome.
 *
 * @property int $id
 * @property int $channel_id
 * @property string|null $builtin
 * @property string|null $name
 * @property string|null $color
 * @property string|null $icon
 * @property bool $show_badge
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Channel|null $channel
 */
class ChannelRank extends AbstractModel
{
    public const BUILTIN_OWNER = 'owner';

    public const BUILTIN_MODERATOR = 'moderator';

    /**
     * Cargos criados pelo dono por canal; os embutidos não contam.
     */
    public const MAX_PER_CHANNEL = 20;

    public const NAME_MAX = 32;

    public const COLOR_PATTERN = '/\A#[0-9a-fA-F]{6}\z/';

    /**
     * Só classe FontAwesome (forma curta ou longa). Vai num atributo `class`,
     * então só letras, dígitos e hífens.
     */
    public const ICON_PATTERN = '/\A(?:fa[srb]?|fa-(?:solid|regular|brands)) fa-[a-z0-9-]{1,48}\z/';

    protected $table = 'chat_channel_ranks';

    public $timestamps = true;

    protected $casts = [
        'channel_id' => 'integer',
        'show_badge' => 'boolean',
        'position'   => 'integer',
    ];

    /**
     * @return array<int, string>
     */
    public static function builtins(): array
    {
        return [self::BUILTIN_OWNER, self::BUILTIN_MODERATOR];
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function isBuiltin(): bool
    {
        return $this->builtin !== null;
    }
}
