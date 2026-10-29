<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Limita envios de mensagem por usuário.
 *
 * No chat postar é frequente, então um endpoint sem limite vira vetor de flood.
 * Equivale ao "chat max messages per second" do Discourse.
 */
class RateLimiter
{
    public function __construct(
        protected ActionThrottle $throttle,
        protected SettingsRepositoryInterface $settings,
        protected Translator $translator
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function assertWithinLimit(User $actor): void
    {
        // Admins ficam isentos: são quem precisaria corrigir um limite mal configurado.
        if ($actor->isAdmin()) {
            return;
        }

        $perSecond = (float) $this->settings->get('ramon-chat.max_messages_per_second', 2);

        if ($perSecond <= 0) {
            return;
        }

        // Janela fixa de um segundo com `add` + `increment` (atômicos), para envios
        // em paralelo não passarem todos.
        if (! $this->throttle->attempt('rate.'.$actor->id, (int) ceil($perSecond), 1)) {
            throw new ValidationException([
                'content' => $this->translator->trans('ramon-chat.api.rate_limited'),
            ]);
        }
    }
}
