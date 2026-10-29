<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Neutralizada. Ressemear permissões a cada update desfazia revogações feitas
 * pelo admin. A semente inicial fica em `2026_07_29_000013`; depois disso a
 * configuração é do fórum.
 *
 * O arquivo continua porque o nome já está na tabela `migrations` de quem atualizou.
 */
return [
    'up'   => fn () => null,
    'down' => fn () => null,
];
