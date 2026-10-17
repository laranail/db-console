<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Servers\ScopedSession;
use Simtabi\Laranail\DBConsole\Engines\PostgresEngine;
use Simtabi\Laranail\DBConsole\Servers\AdminConnection;
use Simtabi\Laranail\DBConsole\Servers\ScopedConnections;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\MintsScopedStatements;

/*
 * Postgres grants act on the database the session is connected to, so a
 * statement scoped to a database has to run on a connection to it. SQLite
 * stands in here: its "database" is a file, so a scoped statement landing in
 * the right file is observable without a server. The live group covers
 * Postgres itself.
 */

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/db-console-scoped-' . bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->cwd = (string) getcwd();
    // SQLite resolves a relative database name against the working directory,
    // which lets a DbName ("target_db") name a file.
    chdir($this->dir);
    touch('admin_db');
    touch('target_db');

    config()->set('database.connections.scoped_admin', [
        'driver' => 'sqlite', 'database' => 'admin_db', 'prefix' => '',
    ]);
    $this->admin = new AdminConnection('local', DB::connection('scoped_admin'));
});

afterEach(function (): void {
    DB::purge('scoped_admin');
    chdir($this->cwd);
    array_map(unlink(...), (array) glob($this->dir . '/*'));
    rmdir($this->dir);
});

function scopedTables(string $file): array
{
    $pdo = new PDO('sqlite:' . $file);

    return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}

it('refuses to run a statement scoped to another database on the admin connection', function (): void {
    $engine = new class
    {
        use MintsScopedStatements;
    };

    // The second statement is scoped to target_db. Before the scope existed
    // it ran here, against the admin's own database, and succeeded.
    expect(fn () => $this->admin->run($engine->list()))
        ->toThrow(LogicException::class, "scoped to database 'target_db'")
        ->and(scopedTables($this->dir . '/target_db'))->toBe([]);
});

it('marks Postgres grants for the target database', function (): void {
    $grant = (new PostgresEngine)->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly),
        new DbName('target_db'),
        new Username('u'),
        new Host('%'),
    )->all();

    foreach ($grant as $statement) {
        expect($this->admin->runsHere($statement))->toBeFalse();
    }
});

it('runs each statement on the database it is scoped to', function (): void {
    $engine = new class
    {
        use MintsScopedStatements;
    };

    app(ScopedConnections::class)->run($this->admin, $engine->list());

    expect(scopedTables($this->dir . '/admin_db'))->toBe(['on_admin'])
        ->and(scopedTables($this->dir . '/target_db'))->toBe(['on_target']);
});

it('closes the connection it opened, so a later DROP DATABASE is not blocked by it', function (): void {
    $engine = new class
    {
        use MintsScopedStatements;
    };

    app(ScopedConnections::class)->run($this->admin, $engine->list());

    expect(array_keys(DB::getConnections()))->not->toContain('db-console-scoped:local:target_db');
});

it('reads a scalar from the database a statement is scoped to', function (): void {
    new PDO('sqlite:' . $this->dir . '/target_db')->exec('CREATE TABLE t (id INTEGER); INSERT INTO t VALUES (1), (2)');

    $engine = new class
    {
        use MintsScopedStatements;
    };

    expect((int) app(ScopedConnections::class)->scalar($this->admin, $engine->countOnTarget()))->toBe(2);
});

it('commits the work of a transaction on every connection it touched', function (): void {
    $engine = new class
    {
        use MintsScopedStatements;
    };

    app(ScopedConnections::class)->transaction($this->admin, function (ScopedSession $session) use ($engine): void {
        $session->run($engine->list());
    });

    expect(scopedTables($this->dir . '/admin_db'))->toBe(['on_admin'])
        ->and(scopedTables($this->dir . '/target_db'))->toBe(['on_target']);
});

// SQLite, like Postgres, has transactional DDL, so a rolled-back CREATE TABLE
// is observable.
it('rolls back every connection it touched when the work throws', function (): void {
    $engine = new class
    {
        use MintsScopedStatements;
    };

    expect(fn () => app(ScopedConnections::class)->transaction($this->admin, function (ScopedSession $session) use ($engine): void {
        $session->run($engine->list());

        throw new RuntimeException('verification failed');
    }))->toThrow(RuntimeException::class, 'verification failed');

    expect(scopedTables($this->dir . '/admin_db'))->toBe([])
        ->and(scopedTables($this->dir . '/target_db'))->toBe([])
        ->and(array_keys(DB::getConnections()))->not->toContain('db-console-scoped:local:target_db')
        // The admin connection outlives the transaction, so closing it does
        // not end one left open: it has to be rolled back.
        ->and(DB::connection('scoped_admin')->transactionLevel())->toBe(0);
});

it('keeps what was committed at a checkpoint when later work throws', function (): void {
    $engine = new class
    {
        use MintsScopedStatements;
    };

    expect(fn () => app(ScopedConnections::class)->transaction($this->admin, function (ScopedSession $session) use ($engine): void {
        $session->run($engine->targetOnly());
        $session->commit();
        $session->run($engine->adminOnly());

        throw new RuntimeException('drop failed');
    }))->toThrow(RuntimeException::class);

    expect(scopedTables($this->dir . '/target_db'))->toBe(['on_target'])
        ->and(scopedTables($this->dir . '/admin_db'))->toBe([]);
});

it('reads rows inside the transaction, seeing its own uncommitted work', function (): void {
    $engine = new class
    {
        use MintsScopedStatements;
    };

    $rows = app(ScopedConnections::class)->transaction($this->admin, function (ScopedSession $session) use ($engine): array {
        $session->run($engine->targetOnly());

        return $session->select($engine->tablesOnTarget());
    });

    expect($rows)->toBe([['name' => 'on_target']]);
});

// A read that has to see inside each database (Postgres grants on schemas and
// tables) returns the rows of every statement, each from its own database.
it('reads rows from the database each statement is scoped to, in order', function (): void {
    new PDO('sqlite:' . $this->dir . '/target_db')->exec('CREATE TABLE on_target (id INTEGER)');
    new PDO('sqlite:' . $this->dir . '/admin_db')->exec('CREATE TABLE on_admin (id INTEGER)');

    $engine = new class
    {
        use MintsScopedStatements;
    };

    $rows = app(ScopedConnections::class)->select($this->admin, $engine->tablesEverywhere());

    expect($rows)->toBe([['name' => 'on_admin'], ['name' => 'on_target']])
        ->and(array_keys(DB::getConnections()))->not->toContain('db-console-scoped:local:target_db');
});
