<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Contador de janela fixa para as ações secundárias do chat: reações, digitação,
 * rascunhos, favoritos e entregas de webhook.
 *
 * Envios têm limiter próprio, configurável. Estas ações ninguém faz mais que
 * poucas vezes por segundo; o teto impede um script de virá-las em enxurrada.
 * `add` + `increment` para que duas requisições simultâneas não passem do teto;
 * a chave carrega o início da janela e simplesmente expira.
 */
class ActionThrottle
{
    public function __construct(
        protected Cache $cache
    ) {
    }

    /**
     * Registra uma tentativa e diz se ficou dentro do limite.
     */
    public function attempt(string $key, int $max, int $windowSeconds): bool
    {
        $windowSeconds = max(1, $windowSeconds);
        $window = intdiv(time(), $windowSeconds);
        $cacheKey = 'ramon-chat.throttle.'.$key.'.'.$window;

        $this->cache->add($cacheKey, 0, $windowSeconds * 2);

        return (int) $this->cache->increment($cacheKey) <= $max;
    }
}
