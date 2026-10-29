<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\User\Guest;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Base dos testes que tocam um model Eloquent ou montam uma query.
 *
 * Registra uma conexão SQLite em memória como resolver em vez de mock: o
 * Eloquent usa a conexão em lugares pouco óbvios (o cast de data passa por
 * `getConnection()->getQueryGrammar()`), e um mock parcial falha como se fosse
 * bug do código testado. Nenhum schema é criado: as queries só são compiladas
 * para SQL e conferidas.
 */
abstract class QueryTestCase extends TestCase
{
    private static ?Manager $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $capsule = new Manager();

        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Vira o resolver de todo model, que é o que os casts de data e a grammar
        // precisam.
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    protected function state(Builder $query): DatabaseSearchState
    {
        $state = new DatabaseSearchState(new Guest(), false);
        $state->setQuery($query);

        return $state;
    }

    /**
     * Normaliza espaços para a asserção tratar do predicado, não de como a grammar
     * espaçou o SQL.
     */
    protected function sql(Builder $query): string
    {
        return preg_replace('/\s+/', ' ', $query->toSql()) ?? '';
    }
}
