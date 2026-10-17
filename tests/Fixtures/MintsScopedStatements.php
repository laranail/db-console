<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Statement;
use Simtabi\Laranail\DBConsole\Domain\StatementList;

/**
 * Stands in for an engine in the routing tests: mints one statement for the
 * admin connection's database and one scoped to `target_db`, so where each
 * one landed is observable in SQLite files.
 */
trait MintsScopedStatements
{
    public function list(): StatementList
    {
        return new StatementList(
            Statement::plain('CREATE TABLE on_admin (id INTEGER)'),
            Statement::plain('CREATE TABLE on_target (id INTEGER)')->inDatabase(new DbName('target_db')),
        );
    }

    public function targetOnly(): StatementList
    {
        return new StatementList(
            Statement::plain('CREATE TABLE on_target (id INTEGER)')->inDatabase(new DbName('target_db')),
        );
    }

    public function adminOnly(): StatementList
    {
        return new StatementList(Statement::plain('CREATE TABLE on_admin (id INTEGER)'));
    }

    public function tablesOnTarget(): Statement
    {
        return Statement::plain("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->inDatabase(new DbName('target_db'));
    }

    public function countOnTarget(): Statement
    {
        return Statement::plain('SELECT COUNT(*) FROM t')->inDatabase(new DbName('target_db'));
    }
}
