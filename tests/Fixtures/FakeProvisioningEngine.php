<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use ReflectionProperty;
use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Engines\Engine;
use Simtabi\Laranail\DBConsole\Exceptions\WeakPassword;
use Simtabi\Laranail\DBConsole\Engines\PasswordConstrainedEngine;
use Simtabi\Laranail\DBConsole\Engines\ServerVersionAwareEngine;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Domain\Statement;
use Simtabi\Laranail\DBConsole\Domain\Capabilities;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Servers\AdminConnection;
use Simtabi\Laranail\DBConsole\Domain\EncryptionCapabilities;
use Simtabi\Laranail\DBConsole\Engines\EmptinessProbingEngine;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * A provisioning server played by three SQLite tables, so the services' create
 * paths (adopt, refuse, apply a password, roll back) run end to end without a
 * real server. Accounts and databases are rows; a password is recorded as its
 * SHA-256, so a test can tell whether one was applied without the value being
 * stored anywhere.
 *
 * Identifiers are the package's own validated value objects, so interpolating
 * them here is as safe as it is in the real engines.
 */
final class FakeProvisioningEngine implements EmptinessProbingEngine, Engine, PasswordConstrainedEngine, ServerVersionAwareEngine
{
    /** The server version the last database was created for, when asked for one. */
    public static ?int $createdForVersion = null;

    private ?int $version = null;

    public bool $failGrants = false;

    /**
     * Another process creates the account or database between db-console's
     * existence check and its create. Building the create statements plays the
     * other process (the row is written straight away), so the create's first
     * statement fails on the unique key, as the duplicate would on a server.
     */
    public bool $raceOnCreate = false;

    /**
     * The create's first statement succeeds and a later one fails, as SQL
     * Server's CREATE LOGIN then CREATE USER can: the resource now exists
     * because db-console half-made it, not because anyone else did.
     */
    public bool $failAfterCreate = false;

    /** Refuse every password, as Postgres refuses one SASLprep would rewrite. */
    public bool $refusesPasswords = false;

    public static function install(string $server = 'fake', string $connection = 'fake_admin'): self
    {
        config()->set("database.connections.{$connection}", ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set("laranail.db-console.servers.{$server}", [
            'engine' => 'pgsql', 'connection' => $connection, 'tls' => ['enabled' => false],
        ]);

        $db = DB::connection($connection);
        $db->statement('CREATE TABLE fake_accounts (name TEXT, host TEXT, password_sha TEXT, UNIQUE (name, host))');
        $db->statement('CREATE TABLE fake_databases (name TEXT UNIQUE, objects INTEGER DEFAULT 0)');
        $db->statement('CREATE TABLE fake_grants (name TEXT, host TEXT, db TEXT)');

        $engine = new self;
        $registry = app(ServerRegistry::class);
        $resolved = new ReflectionProperty(ServerRegistry::class, 'resolved');
        $resolved->setValue($registry, [
            ...$resolved->getValue($registry),
            $server => [$engine, new AdminConnection($server, $db)],
        ]);

        return $engine;
    }

    /**
     * The password an account already had before db-console saw it, generated
     * once per run so no literal credential sits in the repository.
     */
    public static function theirPassword(): string
    {
        static $password = null;

        return $password ??= Password::generate()->reveal();
    }

    public static function addAccount(string $name, string $host = '%', ?string $password = null, string $connection = 'fake_admin'): void
    {
        DB::connection($connection)->insert('INSERT INTO fake_accounts VALUES (?, ?, ?)', [$name, $host, hash('sha256', $password ?? self::theirPassword())]);
    }

    public static function addDatabase(string $name, int $objects = 0, string $connection = 'fake_admin'): void
    {
        DB::connection($connection)->insert('INSERT INTO fake_databases VALUES (?, ?)', [$name, $objects]);
    }

    public static function addGrant(string $name, string $db, string $host = '%', string $connection = 'fake_admin'): void
    {
        DB::connection($connection)->insert('INSERT INTO fake_grants VALUES (?, ?, ?)', [$name, $host, $db]);
    }

    public static function passwordOf(string $name, string $connection = 'fake_admin'): ?string
    {
        $sha = DB::connection($connection)->scalar('SELECT password_sha FROM fake_accounts WHERE name = ?', [$name]);

        return is_string($sha) ? $sha : null;
    }

    public static function count(string $table, string $name, string $connection = 'fake_admin'): int
    {
        return (int) DB::connection($connection)->scalar("SELECT COUNT(*) FROM {$table} WHERE name = ?", [$name]);
    }

    public function type(): EngineType
    {
        return EngineType::Pgsql;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(true, true, false, false, true, new EncryptionCapabilities(false, false));
    }

    public function serverVersion(): StatementList
    {
        return new StatementList(Statement::plain('SELECT 150004'));
    }

    public function forServerVersion(int $version): static
    {
        $engine = clone $this;
        $engine->version = $version;

        return $engine;
    }

    public function createDatabase(DbName $db, Charset $charset): StatementList
    {
        self::$createdForVersion = $this->version;
        $insert = Statement::plain("INSERT INTO fake_databases (name) VALUES ('{$db->value}')");

        if ($this->raceOnCreate) {
            self::addDatabase($db->value);
        }

        return $this->failAfterCreate
            ? new StatementList($insert, Statement::plain('INSERT INTO no_such_table VALUES (1)'))
            : new StatementList($insert);
    }

    public function dropDatabase(DbName $db): StatementList
    {
        return new StatementList(Statement::plain("DELETE FROM fake_databases WHERE name = '{$db->value}'"));
    }

    public function listDatabases(): StatementList
    {
        return new StatementList(Statement::plain('SELECT name FROM fake_databases'));
    }

    public function countUserObjects(DbName $db): StatementList
    {
        return new StatementList(Statement::plain("SELECT COALESCE(SUM(objects), 0) FROM fake_databases WHERE name = '{$db->value}'"));
    }

    public function assertAcceptsPassword(Password $p): void
    {
        if ($this->refusesPasswords) {
            throw WeakPassword::because('refused by the fake engine', Password::DEFAULT_MIN_LENGTH);
        }
    }

    public function createAccount(Username $u, Host $h, Password $p): StatementList
    {
        // Late, as the real engine refuses: while building the statement.
        $this->assertAcceptsPassword($p);
        $sha = hash('sha256', $p->reveal());

        if ($this->raceOnCreate) {
            // The other process's account, with its own password.
            self::addAccount($u->value, $h->value);
        }

        $insert = Statement::sensitive(
            "INSERT INTO fake_accounts VALUES ('{$u->value}', '{$h->value}', '{$sha}')",
            "INSERT INTO fake_accounts VALUES ('{$u->value}', '{$h->value}', '***')",
        );

        return $this->failAfterCreate
            ? new StatementList($insert, Statement::plain('INSERT INTO no_such_table VALUES (1)'))
            : new StatementList($insert);
    }

    public function dropAccount(Username $u, Host $h): StatementList
    {
        return new StatementList(Statement::plain("DELETE FROM fake_accounts WHERE name = '{$u->value}' AND host = '{$h->value}'"));
    }

    public function setPassword(Username $u, Host $h, Password $p): StatementList
    {
        $sha = hash('sha256', $p->reveal());

        return new StatementList(Statement::sensitive(
            "UPDATE fake_accounts SET password_sha = '{$sha}' WHERE name = '{$u->value}' AND host = '{$h->value}'",
            "UPDATE fake_accounts SET password_sha = '***' WHERE name = '{$u->value}' AND host = '{$h->value}'",
        ));
    }

    public function listAccounts(): StatementList
    {
        return new StatementList(Statement::plain('SELECT name, host FROM fake_accounts'));
    }

    public function grant(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        return new StatementList(Statement::plain($this->failGrants
            ? 'INSERT INTO no_such_table VALUES (1)'
            : "INSERT INTO fake_grants VALUES ('{$u->value}', '{$h->value}', '{$db->value}')"));
    }

    public function revoke(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        return new StatementList(Statement::plain("DELETE FROM fake_grants WHERE name = '{$u->value}' AND db = '{$db->value}'"));
    }

    public function showGrants(Username $u, Host $h): StatementList
    {
        return new StatementList(Statement::plain("SELECT db FROM fake_grants WHERE name = '{$u->value}'"));
    }
}
