<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\User\User;
use Ramon\Chat\Channel;

/**
 * Responde "este ator vê o canal N" uma vez por conjunto de permissões, para o
 * fan-out de broadcast e notificações. Vale porque a visibilidade depende só das
 * permissões e da filiação, e os chamadores já leram os membros. A chave é
 * `permissionGroupIds()`, que aplica os processors (flarum/suspend rebaixa ali).
 * Uma instância por fan-out, não singleton.
 */
class PermissionSetVisibility
{
    /** @var array<string, bool> */
    private array $answers = [];

    public function channelVisible(User $user, int $channelId): bool
    {
        $key = $channelId.'|'.self::key($user);

        return $this->answers[$key] ??= Channel::whereVisibleTo($user)
            ->whereKey($channelId)
            ->exists();
    }

    /** @var array<string, bool> */
    private array $chatUsers = [];

    /**
     * `ramon-chat.use` agrupado do mesmo jeito: `hasPermission` custaria uma query
     * por membro no fan-out.
     */
    public function usesChat(User $user): bool
    {
        return $this->chatUsers[self::key($user)] ??= $user->hasPermission('ramon-chat.use');
    }

    /**
     * Conjuntos já resolvidos, para testes e diagnóstico.
     */
    public function bucketCount(): int
    {
        return count($this->answers);
    }

    /**
     * Identidade estável de tudo que pode mudar uma resposta de permissão.
     */
    public static function key(User $user): string
    {
        $groupIds = $user->permissionGroupIds();
        sort($groupIds);

        return implode(',', $groupIds);
    }
}
