<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration;

/**
 * Começa cada teste com o cache vazio.
 *
 * O harness recria o banco a cada teste mas mantém o cache em arquivo no tmp.
 * Contadores de throttle e o cooldown de convites moram ali, com ids de canal e
 * usuário que todo teste reusa, então uma recusa num teste suprimiria um
 * convite em outro.
 *
 * Sobe a aplicação, então vem depois de prepareDatabase() e setting().
 */
trait FlushesCache
{
    protected function flushCache(): void
    {
        $this->app()->getContainer()->make('cache.store')->flush();
    }
}
