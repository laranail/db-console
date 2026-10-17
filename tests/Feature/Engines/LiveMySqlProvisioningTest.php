<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\QueryException;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\Privilege;
use Simtabi\Laranail\DBConsole\Doctor\DoctorService;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Services\ProvisioningWizard;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/*
 * Against a real MySQL or MariaDB: the `live-mysql` job in
 * .github/workflows/tests.yml creates an admin holding exactly the grants
 * DoctorService recommends -- not root -- sets the env read below, and runs
 * this group with --fail-on-skipped, so a missing variable fails that job
 * instead of passing it empty. Everywhere else it skips.
 *
 * MySqlEngine's own tests compare SQL strings. Five Postgres defects passed
 * the same kind of test, so the MySQL family gets the same treatment: only a
 * server can say whether the SQL works, and whether the admin it is told to
 * use is allowed to run it.
 */

uses()->group('live-mysql');

const LIVE_MY_PASSWORD = 'Live-My-Account-Password-2026!';

// TEST-NET-3: a host no runner connects from, so an account scoped to it
// exists but cannot log in.
const LIVE_MY_UNREACHABLE_HOST = '203.0.113.%';

function liveMy(string $variable, string $default = ''): string
{
    $value = getenv("DB_CONSOLE_LIVE_MYSQL_{$variable}");

    return $value === false || $value === '' ? $default : $value;
}

function liveMyEngine(): string
{
    return liveMy('ENGINE', 'mysql');
}

/**
 * @return array<string, mixed>
 */
function liveMyConnection(?string $database, string $username, string $password): array
{
    return [
        'driver'    => liveMyEngine(),
        'host'      => liveMy('HOST', '127.0.0.1'),
        'port'      => liveMy('PORT'),
        'database'  => $database,
        'username'  => $username,
        'password'  => $password,
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix'    => '',
        'strict'    => true,
    ];
}

/**
 * A connection to $database as the admin, or as $user -- the second is how
 * an application would use what db-console provisioned.
 */
function liveMyAs(?string $database, ?string $user = null): Connection
{
    $name = liveMyConnectionName($database, $user);
    config()->set("database.connections.{$name}", liveMyConnection(
        $database,
        $user ?? liveMy('ADMIN_USER'),
        $user === null ? liveMy('ADMIN_PASSWORD') : LIVE_MY_PASSWORD,
    ));

    return DB::connection($name);
}

function liveMyConnectionName(?string $database, ?string $user): string
{
    return 'live_my_' . ($user ?? 'admin') . '_' . ($database ?? 'none');
}

function liveMyClose(?string $database, ?string $user = null): void
{
    DB::purge(liveMyConnectionName($database, $user));
}

function liveMyScalar(string $sql, array $bindings = []): mixed
{
    return liveMyAs(null)->scalar($sql, $bindings);
}

function liveMyAccountCount(string $user): int
{
    return (int) liveMyScalar('SELECT COUNT(*) FROM mysql.user WHERE User = ?', [$user]);
}

function liveMyDatabaseCount(string $db): int
{
    return (int) liveMyScalar('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?', [$db]);
}

function liveMyName(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(4));
}

beforeEach(function (): void {
    if (liveMy('PORT') === '' || liveMy('ADMIN_USER') === '') {
        $this->markTestSkipped('needs DB_CONSOLE_LIVE_MYSQL_PORT and _ADMIN_USER (the live-mysql CI job)');
    }

    Gate::before(fn ($user = null): bool => true);
    $this->migrateCatalog();

    config()->set('database.connections.live_mysql', liveMyConnection(null, liveMy('ADMIN_USER'), liveMy('ADMIN_PASSWORD')));
    config()->set('laranail.db-console.servers.live_mysql', [
        'engine' => liveMyEngine(), 'connection' => 'live_mysql', 'tls' => ['enabled' => false],
    ]);
    // The round-trip is about the engine's SQL; backup-before-drop is db-tools'
    // concern and has its own suite.
    config()->set('laranail.db-console.backup.enabled', false);

    $this->cleanup = [];
});

afterEach(function (): void {
    if (! property_exists($this, 'cleanup') || $this->cleanup === null) {
        return;
    }

    // Best effort. A test that already cleaned up leaves nothing here.
    foreach ($this->cleanup as [$kind, $name, $host]) {
        try {
            if ($kind === 'account' && (int) liveMyScalar('SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = ?', [$name, $host]) === 1) {
                liveMyAs(null)->statement("DROP USER '{$name}'@'{$host}'");
            }
            if ($kind === 'database' && liveMyDatabaseCount($name) === 1) {
                liveMyAs(null)->statement("DROP DATABASE `{$name}`");
            }
        } catch (Throwable) {
            // leave it; the container is discarded with the job
        }
    }
});

it('runs as the minimal admin doctor recommends, not root', function (): void {
    expect(liveMyScalar('SELECT CURRENT_USER()'))->toStartWith(liveMy('ADMIN_USER') . '@');

    // Without this the rest of the group proves nothing: root bypasses every
    // privilege check the grant recommendation is about.
    $finding = (fn () => $this->checkAdminPrivileges('live_mysql'))->call(app(DoctorService::class));
    expect($finding->severity)->toBe(Severity::Info);

    if (liveMyEngine() === 'mysql') {
        // The job sets it the way DigitalOcean does; a table without a
        // primary key is then refused.
        expect((int) liveMyScalar('SELECT @@GLOBAL.sql_require_primary_key'))->toBe(1);
    }
});

it('provisions a database an application account can migrate, use and hand back', function (): void {
    $db = liveMyName('shop');
    $user = liveMyName('app');
    $this->cleanup = [['account', $user, '%'], ['database', $db, null]];

    app(ProvisioningWizard::class)->provision(
        'live_mysql',
        new DbName($db),
        new Charset('utf8mb4', 'utf8mb4_unicode_ci'),   // the package default
        new Username($user),
        new Host('%'),
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        new Password(LIVE_MY_PASSWORD),
    );

    $schema = (array) liveMyAs(null)->selectOne(
        'SELECT default_character_set_name AS cs, default_collation_name AS co FROM information_schema.schemata WHERE schema_name = ?',
        [$db],
    );
    expect($schema)->toBe(['cs' => 'utf8mb4', 'co' => 'utf8mb4_unicode_ci']);

    // What `php artisan migrate` sends for an `id()` table, then DML.
    $app = liveMyAs($db, $user);
    $app->statement('CREATE TABLE orders (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, sku varchar(255) NOT NULL, created_at timestamp NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $app->statement('ALTER TABLE orders ADD INDEX orders_sku_index (sku)');
    $app->insert('INSERT INTO orders (sku) VALUES (?)', ['A-1']);
    $app->update('UPDATE orders SET sku = ? WHERE sku = ?', ['A-2', 'A-1']);
    expect($app->scalar('SELECT sku FROM orders'))->toBe('A-2');
    $app->delete('DELETE FROM orders');
    expect((int) $app->scalar('SELECT COUNT(*) FROM orders'))->toBe(0);
    liveMyClose($db, $user);

    expect(app(DatabaseManager::class)->isEmpty('live_mysql', new DbName($db)))->toBeFalse();

    app(AccountManager::class)->drop('live_mysql', new Username($user), new Host('%'));
    expect(liveMyAccountCount($user))->toBe(0);

    app(DatabaseManager::class)->drop('live_mysql', new DbName($db));
    expect(liveMyDatabaseCount($db))->toBe(0);
});

it('can grant every privilege a preset hands out', function (): void {
    $db = liveMyName('full');
    $user = liveMyName('owner');
    $this->cleanup = [['account', $user, '%'], ['database', $db, null]];

    // Full is every Privilege case. If the recommended admin cannot pass one
    // of them on, some preset fails at its grant step on a real server.
    app(ProvisioningWizard::class)->provision(
        'live_mysql',
        new DbName($db),
        new Charset('utf8mb4', 'utf8mb4_unicode_ci'),
        new Username($user),
        new Host('%'),
        PrivilegeSet::fromPreset(PrivilegePreset::Full),
        new Password(LIVE_MY_PASSWORD),
    );

    $grants = strtoupper(implode("\n", array_map(
        static fn (object $row): string => (string) array_values((array) $row)[0],
        liveMyAs(null)->select("SHOW GRANTS FOR '{$user}'@'%'"),
    )));
    expect($grants)->toContain('CREATE TEMPORARY TABLES')
        ->and($grants)->toContain('LOCK TABLES')
        ->and($grants)->toContain('TRIGGER');
});

it('revokes a privilege so the account can still read but no longer write', function (): void {
    $db = liveMyName('reports');
    $user = liveMyName('writer');
    $this->cleanup = [['account', $user, '%'], ['database', $db, null]];

    app(ProvisioningWizard::class)->provision(
        'live_mysql',
        new DbName($db),
        new Charset('utf8mb4', 'utf8mb4_unicode_ci'),
        new Username($user),
        new Host('%'),
        PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite),
        new Password(LIVE_MY_PASSWORD),
    );

    liveMyAs($db)->statement('CREATE TABLE metrics (id int NOT NULL PRIMARY KEY, n int NOT NULL)');
    liveMyAs($db)->insert('INSERT INTO metrics (id, n) VALUES (1, 7)');
    liveMyClose($db);

    app(PrivilegeManager::class)->revoke(
        'live_mysql',
        new Username($user),
        new Host('%'),
        new DbName($db),
        PrivilegeSet::custom([
            Privilege::Insert,
            Privilege::Update,
            Privilege::Delete,
        ]),
    );

    // Database-level privileges are read when a session selects the
    // database, so a fresh connection sees the revoke.
    $reader = liveMyAs($db, $user);
    expect((int) $reader->scalar('SELECT n FROM metrics'))->toBe(7)
        ->and(fn () => $reader->insert('INSERT INTO metrics (id, n) VALUES (2, 8)'))->toThrow(QueryException::class, 'denied')
        ->and(fn () => $reader->statement('CREATE TABLE sneaky (id int PRIMARY KEY)'))->toThrow(QueryException::class, 'denied');
    liveMyClose($db, $user);
});

it('moves an account to a new host and keeps its password and grants', function (): void {
    $db = liveMyName('moved');
    $user = liveMyName('mover');
    $this->cleanup = [['account', $user, LIVE_MY_UNREACHABLE_HOST], ['account', $user, '%'], ['database', $db, null]];

    // Scoped to a host the runner is not on: the account exists, the login
    // is refused. That is what host scoping is for.
    app(ProvisioningWizard::class)->provision(
        'live_mysql',
        new DbName($db),
        new Charset('utf8mb4', 'utf8mb4_unicode_ci'),
        new Username($user),
        new Host(LIVE_MY_UNREACHABLE_HOST),
        PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite),
        new Password(LIVE_MY_PASSWORD),
    );

    expect(fn () => liveMyAs($db, $user)->scalar('SELECT 1'))->toThrow(QueryException::class, 'denied');
    liveMyClose($db, $user);

    // The host change recreates the account from its stored hash -- the
    // password is never known to db-console -- and re-applies the grants.
    app(AccountManager::class)->changeHost('live_mysql', new Username($user), new Host(LIVE_MY_UNREACHABLE_HOST), new Host('%'));

    expect((int) liveMyScalar('SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = ?', [$user, LIVE_MY_UNREACHABLE_HOST]))->toBe(0);

    $app = liveMyAs($db, $user);
    $app->statement('SELECT 1');
    liveMyAs($db)->statement('CREATE TABLE notes (id int NOT NULL PRIMARY KEY, body text)');
    $app->insert('INSERT INTO notes (id, body) VALUES (1, ?)', ['kept']);
    expect($app->scalar('SELECT body FROM notes'))->toBe('kept');
    liveMyClose($db, $user);
    liveMyClose($db);
});

it('drops a database that still has a session connected to it', function (): void {
    $db = liveMyName('busy');
    $this->cleanup = [['database', $db, null]];

    app(DatabaseManager::class)->create('live_mysql', new DbName($db), new Charset('utf8mb4', 'utf8mb4_unicode_ci'));

    $session = liveMyAs($db);
    expect((int) $session->scalar('SELECT 1'))->toBe(1);

    app(DatabaseManager::class)->drop('live_mysql', new DbName($db));
    liveMyClose($db);

    expect(liveMyDatabaseCount($db))->toBe(0);
});

it('reports emptiness from the admin connection, so a rollback drops only what it created', function (): void {
    $empty = liveMyName('empty');
    $used = liveMyName('used');
    $this->cleanup = [['database', $empty, null], ['database', $used, null]];
    $databases = app(DatabaseManager::class);

    $databases->create('live_mysql', new DbName($empty), new Charset('utf8mb4', 'utf8mb4_unicode_ci'));
    $databases->create('live_mysql', new DbName($used), new Charset('utf8mb4', 'utf8mb4_unicode_ci'));
    liveMyAs($used)->statement('CREATE TABLE kept (id int NOT NULL PRIMARY KEY)');
    liveMyClose($used);

    expect($databases->isEmpty('live_mysql', new DbName($empty)))->toBeTrue()
        ->and($databases->isEmpty('live_mysql', new DbName($used)))->toBeFalse();

    $databases->rollbackCreatedDatabase('live_mysql', new DbName($empty));
    $databases->rollbackCreatedDatabase('live_mysql', new DbName($used));

    expect(liveMyDatabaseCount($empty))->toBe(0)
        ->and(liveMyDatabaseCount($used))->toBe(1);
});

it('rolls the wizard back when a later step fails, leaving no database behind', function (): void {
    $db = liveMyName('rolled');
    $user = liveMyName('taken');
    $this->cleanup = [['account', $user, '%'], ['database', $db, null]];

    // The account already exists, so the wizard's second step fails after
    // its first created the database.
    liveMyAs(null)->statement("CREATE USER '{$user}'@'%' IDENTIFIED BY '" . LIVE_MY_PASSWORD . "'");

    expect(fn () => app(ProvisioningWizard::class)->provision(
        'live_mysql',
        new DbName($db),
        new Charset('utf8mb4', 'utf8mb4_unicode_ci'),
        new Username($user),
        new Host('%'),
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        new Password(LIVE_MY_PASSWORD),
    ))->toThrow(Throwable::class);

    expect(liveMyDatabaseCount($db))->toBe(0)
        // The rollback undoes only what the run created: the account it
        // collided with was there before and stays.
        ->and(liveMyAccountCount($user))->toBe(1);
});
