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
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository;
use Ramon\Chat\Channel;

/**
 * Modo lento: intervalo mínimo entre as mensagens de uma pessoa num canal.
 *
 * Diferente do RateLimiter (proteção global contra flood, por segundo): é uma
 * ferramenta de moderação do dono do canal, como no Discord.
 *
 * Fica no cache, não na última mensagem: a checagem roda em todo envio e a
 * chave com TTL expira sozinha. Limpar o cache zera os cooldowns, o que é
 * aceitável: não é controle de segurança.
 */
class SlowMode
{
    public function __construct(
        protected Repository $cache,
        protected Translator $translator,
        protected ChannelOwnership $ownership
    ) {
    }

    /**
     * Recusa o envio se o ator ainda está no cooldown do canal.
     *
     * @throws ValidationException
     */
    public function assertMayPost(Channel $channel, User $actor): void
    {
        $seconds = $this->secondsFor($channel);

        if ($seconds <= 0 || $this->isExempt($channel, $actor)) {
            return;
        }

        $remaining = $this->remainingFor($channel, $actor);

        if ($remaining <= 0) {
            return;
        }

        throw new ValidationException([
            'content' => $this->translator->trans(
                'ramon-chat.api.slow_mode_wait',
                ['seconds' => $remaining]
            ),
        ]);
    }

    /**
     * Reserva a vez do ator, de forma atômica, logo antes de gravar a mensagem.
     *
     * A checagem acima sozinha deixa N envios em paralelo passarem. O `add` só
     * grava se a chave não existe, então só um fica com a vez.
     *
     * @throws ValidationException
     */
    public function claim(Channel $channel, User $actor): void
    {
        $seconds = $this->secondsFor($channel);

        if ($seconds <= 0 || $this->isExempt($channel, $actor)) {
            return;
        }

        if ($this->cache->add($this->key($channel, $actor), time() + $seconds, $seconds + 1)) {
            return;
        }

        throw new ValidationException([
            'content' => $this->translator->trans(
                'ramon-chat.api.slow_mode_wait',
                ['seconds' => max(1, $this->remainingFor($channel, $actor))]
            ),
        ]);
    }

    /**
     * Inicia o cooldown. Chamado só depois de aceitar a mensagem: envio que falha
     * na validação não custa a vez.
     */
    public function noteSent(Channel $channel, User $actor): void
    {
        $seconds = $this->secondsFor($channel);

        if ($seconds <= 0 || $this->isExempt($channel, $actor)) {
            return;
        }

        // O valor é quando o cooldown termina, então `remainingFor` não depende da
        // janela atual: encurtá-la no meio da conversa não prende ninguém na antiga.
        $this->cache->put($this->key($channel, $actor), time() + $seconds, $seconds + 1);
    }

    /**
     * Segundos que o ator ainda precisa esperar, ou 0.
     */
    public function remainingFor(Channel $channel, User $actor): int
    {
        if ($this->secondsFor($channel) <= 0 || $this->isExempt($channel, $actor)) {
            return 0;
        }

        $until = (int) $this->cache->get($this->key($channel, $actor), 0);

        return max(0, $until - time());
    }

    protected function secondsFor(Channel $channel): int
    {
        return max(0, (int) $channel->slow_mode_seconds);
    }

    /**
     * A quem o modo lento não se aplica.
     *
     * Permissão própria, separada de `ramon-chat.moderate`: um bot ou atendente pode
     * precisar responder sem esperar sem poder moderar, e um fórum pode querer os
     * moderadores no mesmo ritmo. Semeada para MODERATOR. Dono e moderadores do
     * canal também ficam isentos.
     */
    public function isExempt(Channel $channel, User $actor): bool
    {
        return $actor->hasPermission('ramon-chat.bypassSlowMode')
            || $this->ownership->exemptFromSlowMode($actor, $channel);
    }

    protected function key(Channel $channel, User $actor): string
    {
        return 'ramon-chat.slow.'.$channel->id.'.'.$actor->id;
    }
}
