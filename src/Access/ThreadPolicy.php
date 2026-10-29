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
use Ramon\Chat\Thread;

/**
 * Permissões sobre threads.
 *
 * @see ChannelPolicy sobre por que os métodos retornam `?bool` e não `bool`.
 */
class ThreadPolicy extends AbstractPolicy
{
    public function __construct(
        protected VisibilityCache $cache,
        protected ChannelOwnership $ownership
    ) {
    }

    /**
     * Como MessagePolicy::view: respondido a partir da thread em mãos e
     * memoizado, para não rodar um `EXISTS` por linha. Sem o canal carregado, cai
     * na query.
     */
    public function view(User $actor, Thread $thread): ?bool
    {
        $visible = $this->cache->remember(
            $actor,
            'thread',
            (int) $thread->id,
            function () use ($actor, $thread) {
                $channel = $thread->channel;

                if ($channel === null) {
                    return Thread::whereVisibleTo($actor)->whereKey($thread->id)->exists();
                }

                return ScopeThreadVisibility::rowVisibleTo(
                    $thread,
                    ScopeChannelVisibility::visibleTo($actor, $channel, $this->cache)
                        && ScopeChannelVisibility::readsContents($actor, $channel)
                );
            }
        );

        return $visible ?: null;
    }

    public function postMessage(User $actor, Thread $thread): ?bool
    {
        if (! $thread->acceptsMessages()) {
            return false;
        }

        $channel = $thread->channel;

        return $channel !== null && $actor->can('postMessage', $channel) ? true : false;
    }

    /**
     * Renomeia o criador da thread, a moderação do chat ou quem modera o canal.
     */
    public function rename(User $actor, Thread $thread): ?bool
    {
        if ($actor->exists && $actor->id === $thread->creator_id && $this->view($actor, $thread)) {
            return true;
        }

        return $this->moderates($actor, $thread) ? true : null;
    }

    public function close(User $actor, Thread $thread): ?bool
    {
        return $this->moderates($actor, $thread) ? true : null;
    }

    public function delete(User $actor, Thread $thread): ?bool
    {
        return $this->moderates($actor, $thread) ? true : null;
    }

    /**
     * `moderate` no fórum, ou ser dono ou moderador do canal da thread.
     */
    protected function moderates(User $actor, Thread $thread): bool
    {
        if ($actor->hasPermission('ramon-chat.moderate')) {
            return true;
        }

        return $thread->channel !== null && $this->ownership->moderates($actor, $thread->channel);
    }
}
