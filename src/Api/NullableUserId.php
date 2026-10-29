<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api;

/**
 * O id da conta escolhida para os anúncios, ou null quando quem anuncia é o bot.
 */
class NullableUserId
{
    public function __invoke(mixed $value): ?int
    {
        return $value ? (int) $value : null;
    }
}
