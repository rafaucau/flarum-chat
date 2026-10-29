<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Ramon\Chat\Service\ChannelOwnership;

/**
 * Abilities globais do chat. Ao contrário das policies de model, sempre respondem
 * true ou false: abster-se entrega a decisão ao fallback do Gate e ao `can()`
 * genérico de outras extensões, que pode liberar ability desconhecida. O fallback
 * ainda testaria o nome da ability, não a permissão `ramon-chat.*` gravada.
 */
class GlobalPolicy extends AbstractPolicy
{
    public function __construct(
        protected ChannelOwnership $ownership
    ) {
    }

    /**
     * Abrir o chat. A ability é `useChat` e a permissão `ramon-chat.use` porque o
     * Gate despacha por `method_exists`; com um método, o DENY explícito vence o
     * ALLOW de qualquer catch-all de outra extensão.
     */
    public function useChat(User $actor): bool
    {
        return $actor->hasPermission('ramon-chat.use');
    }

    /**
     * Exige também o gate base do chat: um grupo só com `startDirect` não contorna
     * o opt-in.
     */
    public function startDirect(User $actor): bool
    {
        if (! $actor->hasPermission('ramon-chat.use')) {
            return false;
        }

        return $actor->hasPermission('ramon-chat.startDirect');
    }

    /**
     * O modo de posse vem antes da permissão: com canais nas mãos dos admins,
     * ninguém mais cria, diga o que disser a grade de permissões.
     */
    public function createChannel(User $actor): bool
    {
        if (! $actor->hasPermission('ramon-chat.use')) {
            return false;
        }

        if (! $this->ownership->membersOwnChannels()) {
            return $actor->isAdmin();
        }

        return $actor->hasPermission('ramon-chat.createChannel');
    }
}
