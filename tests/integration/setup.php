<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Prepares the throwaway database the integration suite runs against.
 *
 * Run once before the suite:
 *
 *     composer install
 *     php tests/integration/setup.php
 *     vendor/bin/phpunit --testsuite integration
 *
 * The database it points at is dropped and rebuilt on every run, so it must not be
 * the forum's own. Connection details come from the DB_DRIVER / DB_HOST / DB_PORT /
 * DB_DATABASE / DB_USERNAME / DB_PASSWORD / DB_PREFIX environment variables and
 * default to SQLite, so an unattended run needs no arguments. The resulting config
 * is written to FLARUM_TEST_TMP_DIR_LOCAL (tests/integration/tmp in the core's
 * reusable workflow) or, failing that, under vendor/flarum/testing.
 */

use Flarum\Testing\integration\Setup\SetupScript;

require __DIR__.'/../../vendor/autoload.php';

$setup = new SetupScript();

$setup->run();

// O cache do catálogo de traduções grava aqui; sem a pasta, cada teste que
// traduz algo dispara um warning.
$tmpDir = getenv('FLARUM_TEST_TMP_DIR_LOCAL') ?: getenv('FLARUM_TEST_TMP_DIR') ?: __DIR__.'/../../vendor/flarum/testing/src/integration/tmp';
@mkdir($tmpDir.'/storage/locale', 0777, true);
