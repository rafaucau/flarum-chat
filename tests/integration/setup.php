<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Prepara o banco descartável em que a suíte de integração roda.
 *
 * Rode uma vez antes da suíte:
 *
 *     composer install
 *     php tests/integration/setup.php
 *     vendor/bin/phpunit --testsuite integration
 *
 * O banco é apagado e recriado a cada execução, então não pode ser o do fórum.
 * A conexão vem de DB_DRIVER / DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME /
 * DB_PASSWORD / DB_PREFIX e cai em SQLite por padrão, sem precisar de
 * argumentos. A config vai para FLARUM_TEST_TMP_DIR_LOCAL (tests/integration/tmp
 * no workflow reutilizável do core) ou, sem ele, para vendor/flarum/testing.
 */

use Flarum\Testing\integration\Setup\SetupScript;

require __DIR__.'/../../vendor/autoload.php';

$setup = new SetupScript();

$setup->run();

// O cache do catálogo de traduções grava aqui; sem a pasta, cada teste que
// traduz algo dispara um warning.
$tmpDir = getenv('FLARUM_TEST_TMP_DIR_LOCAL') ?: getenv('FLARUM_TEST_TMP_DIR') ?: __DIR__.'/../../vendor/flarum/testing/src/integration/tmp';
@mkdir($tmpDir.'/storage/locale', 0777, true);
