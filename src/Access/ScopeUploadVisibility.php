<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Message;

/**
 * Upload é visível quando a mensagem dele é. Sem mensagem, só o autor vê: é o
 * anexo pendente no composer. Fica como scope do model porque o UploadResource e
 * o ServeUploadController servem os mesmos arquivos e precisam da mesma regra.
 */
class ScopeUploadVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        $query->where(function (Builder $query) use ($actor) {
            $query->whereIn('chat_uploads.message_id', function ($sub) use ($actor) {
                Message::query()
                    ->setQuery($sub->from('chat_messages'))
                    // @phpstan-ignore method.notFound (Flarum model scope)
                    ->whereVisibleTo($actor)
                    ->select('chat_messages.id');
            });

            if ($actor->exists) {
                $query->orWhere(function (Builder $query) use ($actor) {
                    $query
                        ->whereNull('chat_uploads.message_id')
                        ->where('chat_uploads.user_id', $actor->id);
                });
            }
        });
    }
}
