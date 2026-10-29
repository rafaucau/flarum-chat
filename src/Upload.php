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
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arquivo anexado a uma mensagem.
 *
 * `storage` diz onde estão os bytes (`local` ou `fof`); `is_private` diz como
 * são servidos (direto ou via ServeUploadController após checar visibilidade).
 * `fof` com `is_private` é transitório: o arquivo está voltando ao disco privado
 * e já não se entrega a URL pública.
 *
 * @property int $id
 * @property int|null $message_id
 * @property int|null $user_id
 * @property string $path
 * @property bool $is_private
 * @property string $storage
 * @property string|null $storage_adapter
 * @property string|null $remote_url
 * @property string $file_name
 * @property string|null $mime_type
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Message|null $message
 * @property-read User|null $user
 */
class Upload extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const STORAGE_LOCAL = 'local';
    public const STORAGE_FOF = 'fof';

    protected $table = 'chat_uploads';

    /**
     * Injetado pelo ChatServiceProvider (ver Channel::$extensions sobre o static).
     */
    protected static ?UrlGenerator $url = null;

    public static function setUrlGenerator(UrlGenerator $url): void
    {
        static::$url = $url;
    }

    public $timestamps = true;

    protected $casts = [
        'message_id' => 'integer',
        'user_id'    => 'integer',
        'is_private' => 'boolean',
        'size'       => 'integer',
        'width'      => 'integer',
        'height'     => 'integer',
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

    public function isImage(): bool
    {
        return $this->mime_type !== null && str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Órfão: o composer nunca enviou. O comando de retenção os remove.
     */
    public function isOrphaned(): bool
    {
        return $this->message_id === null;
    }

    /**
     * Bytes fora dos discos do chat.
     */
    public function isRemote(): bool
    {
        return $this->storage === self::STORAGE_FOF;
    }

    /**
     * Público: URL direta. Privado: por id via ServeUploadController, que checa
     * quem pede. A privacidade é lida primeiro para nunca entregar a URL pública
     * de um arquivo remoto a caminho do disco privado.
     */
    public function url(): string
    {
        $url = static::$url;

        if ($this->is_private) {
            return $url->to('api')->route('chat.uploads.file', ['id' => $this->id]);
        }

        if ($this->isRemote() && $this->remote_url) {
            return $this->remote_url;
        }

        return $url->to('forum')->path('assets/chat/'.$this->path);
    }
}
