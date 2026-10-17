<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\QueryException;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Exceptions\GrantNotApplied;
use Simtabi\Laranail\DBConsole\Services\ProvisioningWizard;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Exceptions\InsufficientPrivilege;

/*
 * Against a real PostgreSQL 18: the `live-pgsql` job in
 * .github/workflows/tests.yml creates an admin role with CREATEDB CREATEROLE
 * and nothing else -- the shape of DigitalOcean's doadmin -- sets the env read
 * below, and runs this group with --fail-on-skipped, so a missing variable
 * fails that job instead of passing it empty. Everywhere else it skips.
 *
 * The engine tests compare SQL strings, and every defect fixed here passed
 * them: an encoding Postgres does not have, grants that landed in the admin's
 * own database, CREATE in a table grant, and drops that a connected session
 * or an owned object refuses. Only a server can say whether the SQL works.
 */

uses()->group('live-pgsql');

const LIVE_PG_PASSWORD = 'Live-Pg-Role-Password-2026!';

function livePg(string $variable, string $default = ''): string
{
    $value = getenv("DB_CONSOLE_LIVE_PGSQL_{$variable}");

    return $value === false || $value === '' ? $default : $value;
}

/**
 * @return array<string, mixed>
 */
function livePgConnection(string $database, string $username, string $password): array
{
    return [
        'driver'   => 'pgsql',
        'host'     => livePg('HOST', '127.0.0.1'),
        'port'     => livePg('PORT'),
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'charset'  => 'utf8',
        'prefix'   => '',
        'schema'   => 'public',
        'sslmode'  => 'disable',
    ];
}

/**
 * A connection to $database as the admin, or as $role -- the second is how
 * an application would use what db-console provisioned.
 */
function livePgAs(string $database, ?string $role = null): Connection
{
    $name = 'live_pg_' . ($role ?? 'admin') . '_' . $database;
    config()->set("database.connections.{$name}", livePgConnection(
        $database,
        $role ?? livePg('ADMIN_USER'),
        $role === null ? livePg('ADMIN_PASSWORD') : LIVE_PG_PASSWORD,
    ));

    return DB::connection($name);
}

/**
 * A connection to $database as the server's superuser. Used only to build
 * fixtures the restricted admin cannot: databases it does not own, objects
 * it cannot reach, locks it does not hold.
 */
function livePgSuper(string $database): Connection
{
    $name = 'live_pg_super_' . $database;
    config()->set("database.connections.{$name}", livePgConnection(
        $database,
        livePg('SUPERUSER'),
        livePg('SUPERUSER_PASSWORD'),
    ));

    return DB::connection($name);
}

function livePgVersion(): int
{
    return (int) livePgScalar('SHOW server_version_num');
}

function livePgClose(string $database, ?string $role = null): void
{
    DB::purge('live_pg_' . ($role ?? 'admin') . '_' . $database);
}

function livePgScalar(string $sql, array $bindings = []): mixed
{
    return livePgAs('postgres')->scalar($sql, $bindings);
}

function liveName(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(4));
}

beforeEach(function (): void {
    if (livePg('PORT') === '' || livePg('ADMIN_USER') === '' || livePg('SUPERUSER') === '') {
        $this->markTestSkipped('needs DB_CONSOLE_LIVE_PGSQL_PORT, _ADMIN_USER and _SUPERUSER (the live-pgsql CI job)');
    }

    Gate::before(fn ($user = null): bool => true);
    $this->migrateCatalog();

    config()->set('database.connections.live_pgsql', livePgConnection('postgres', livePg('ADMIN_USER'), livePg('ADMIN_PASSWORD')));
    config()->set('laranail.db-console.servers.live_pgsql', [
        'engine' => 'pgsql', 'connection' => 'live_pgsql', 'tls' => ['enabled' => false],
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

    // Best effort, in the order Postgres allows: roles release their objects,
    // then databases go. A test that already cleaned up leaves nothing here.
    foreach ($this->cleanup as [$kind, $name]) {
        try {
            if ($kind === 'role' && (int) livePgScalar('SELECT COUNT(*) FROM pg_roles WHERE rolname = ?', [$name]) === 1) {
                app(AccountManager::class)->drop('live_pgsql', new Username($name), new Host('%'));
            }
            if ($kind === 'superuser-database') {
                DB::purge('live_pg_super_' . $name);
                livePgSuper('postgres')->statement("DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)");
            }
            if ($kind === 'database' && (int) livePgScalar('SELECT COUNT(*) FROM pg_database WHERE datname = ?', [$name]) === 1) {
                app(DatabaseManager::class)->drop('live_pgsql', new DbName($name));
            }
        } catch (Throwable) {
            // leave it; the container is discarded with the job
        }
    }
});

it('runs as an admin that is not a superuser, the way a managed service hands it out', function (): void {
    $role = (array) livePgAs('postgres')->selectOne(
        'SELECT rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = current_user',
    );

    // Without this the rest of the group proves nothing: a superuser bypasses
    // every privilege check the fixes are about.
    expect($role['rolsuper'])->toBeFalse()
        ->and($role['rolcreatedb'])->toBeTrue()
        ->and($role['rolcreaterole'])->toBeTrue();
});

it('provisions a database an application role can migrate, use and hand back', function (): void {
    $db = liveName('shop');
    $role = liveName('app');
    $this->cleanup = [['role', $role], ['database', $db]];

    app(ProvisioningWizard::class)->provision(
        'live_pgsql',
        new DbName($db),
        new Charset('utf8mb4', 'utf8mb4_unicode_ci'),   // the package default, a MySQL name
        new Username($role),
        new Host('%'),
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        new Password(LIVE_PG_PASSWORD),
    );

    expect(livePgScalar('SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = ?', [$db]))->toBe('UTF8');

    // The application runs its own migrations: CREATE on schema public, which
    // PG15+ no longer gives to PUBLIC.
    $app = livePgAs($db, $role);
    $app->statement('CREATE TABLE orders (id bigserial PRIMARY KEY, sku text NOT NULL)');
    $app->insert('INSERT INTO orders (sku) VALUES (?)', ['A-1']);
    expect((int) $app->scalar('SELECT COUNT(*) FROM orders'))->toBe(1);

    // A table the admin creates after the grant is covered by the default
    // privileges, not just the tables that existed when it ran.
    livePgAs($db)->statement('CREATE TABLE reference_data (code text PRIMARY KEY)');
    livePgAs($db)->insert('INSERT INTO reference_data (code) VALUES (?)', ['KES']);
    livePgClose($db);
    expect($app->scalar('SELECT code FROM reference_data'))->toBe('KES');
    livePgClose($db, $role);

    expect(app(DatabaseManager::class)->isEmpty('live_pgsql', new DbName($db)))->toBeFalse();

    // The role owns `orders` inside $db and holds grants there; DROP ROLE
    // alone refuses both.
    app(AccountManager::class)->drop('live_pgsql', new Username($role), new Host('%'));
    expect((int) livePgScalar('SELECT COUNT(*) FROM pg_roles WHERE rolname = ?', [$role]))->toBe(0);

    app(DatabaseManager::class)->drop('live_pgsql', new DbName($db));
    expect((int) livePgScalar('SELECT COUNT(*) FROM pg_database WHERE datname = ?', [$db]))->toBe(0);
});

it('grants a read-only role SELECT and nothing it can write with', function (): void {
    $db = liveName('reports');
    $role = liveName('reader');
    $this->cleanup = [['role', $role], ['database', $db]];

    app(ProvisioningWizard::class)->provision(
        'live_pgsql',
        new DbName($db),
        new Charset('utf8'),
        new Username($role),
        new Host('%'),
        PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly),
        new Password(LIVE_PG_PASSWORD),
    );

    livePgAs($db)->statement('CREATE TABLE metrics (n int)');
    livePgAs($db)->insert('INSERT INTO metrics (n) VALUES (7)');
    livePgClose($db);

    $reader = livePgAs($db, $role);
    expect((int) $reader->scalar('SELECT n FROM metrics'))->toBe(7)
        ->and(fn () => $reader->insert('INSERT INTO metrics (n) VALUES (8)'))->toThrow(QueryException::class, 'permission denied');

    // PG15 revoked CREATE on schema public from PUBLIC. Before it every role
    // could create tables there, and the schema belongs to the bootstrap
    // superuser, so a CREATEROLE admin cannot take that away. Pinned so a
    // change in either direction is seen.
    if (livePgVersion() >= 150000) {
        expect(fn () => $reader->statement('CREATE TABLE sneaky (n int)'))->toThrow(QueryException::class, 'permission denied');
    } else {
        $reader->statement('CREATE TABLE sneaky (n int)');
        expect((int) $reader->scalar("SELECT COUNT(*) FROM pg_tables WHERE tablename = 'sneaky'"))->toBe(1);
    }
    livePgClose($db, $role);
});

it('drops a database that still has a session connected to it', function (): void {
    $db = liveName('busy');
    $this->cleanup = [['database', $db]];

    app(DatabaseManager::class)->create('live_pgsql', new DbName($db), new Charset('utf8'));

    // An open session, as DigitalOcean's monitoring and every pooler keep.
    // A plain DROP DATABASE refuses with "is being accessed by other users".
    $session = livePgAs($db);
    expect((int) $session->scalar('SELECT 1'))->toBe(1);

    app(DatabaseManager::class)->drop('live_pgsql', new DbName($db));
    livePgClose($db);

    expect((int) livePgScalar('SELECT COUNT(*) FROM pg_database WHERE datname = ?', [$db]))->toBe(0);
});

it('reports emptiness from inside the database, so a wizard rollback drops only what it created', function (): void {
    $empty = liveName('empty');
    $used = liveName('used');
    $this->cleanup = [['database', $empty], ['database', $used]];
    $databases = app(DatabaseManager::class);

    $databases->create('live_pgsql', new DbName($empty), new Charset('utf8'));
    $databases->create('live_pgsql', new DbName($used), new Charset('utf8'));
    livePgAs($used)->statement('CREATE TABLE kept (id int)');
    livePgClose($used);

    expect($databases->isEmpty('live_pgsql', new DbName($empty)))->toBeTrue()
        ->and($databases->isEmpty('live_pgsql', new DbName($used)))->toBeFalse();

    $databases->rollbackCreatedDatabase('live_pgsql', new DbName($empty));
    $databases->rollbackCreatedDatabase('live_pgsql', new DbName($used));

    expect((int) livePgScalar('SELECT COUNT(*) FROM pg_database WHERE datname = ?', [$empty]))->toBe(0)
        ->and((int) livePgScalar('SELECT COUNT(*) FROM pg_database WHERE datname = ?', [$used]))->toBe(1);
});

it('refuses to drop a role before changing anything when a database it owns objects in is out of reach', function (): void {
    $db = liveName('shop');
    $locked = liveName('locked');
    $role = liveName('app');
    $this->cleanup = [['superuser-database', $locked], ['role', $role], ['database', $db]];

    app(ProvisioningWizard::class)->provision('live_pgsql', new DbName($db), new Charset('utf8'), new Username($role), new Host('%'), PrivilegeSet::fromPreset(PrivilegePreset::AppStandard), new Password(LIVE_PG_PASSWORD));
    livePgAs($db, $role)->statement('CREATE TABLE orders (id int)');
    livePgClose($db, $role);

    // A database the admin may not connect to, where the role holds a grant.
    $super = livePgSuper('postgres');
    $super->statement("CREATE DATABASE \"{$locked}\"");
    $super->statement("REVOKE CONNECT ON DATABASE \"{$locked}\" FROM PUBLIC");
    livePgSuper($locked)->statement('CREATE TABLE secrets (n int)');
    livePgSuper($locked)->statement("GRANT SELECT ON secrets TO \"{$role}\"");
    DB::purge('live_pg_super_' . $locked);

    $heldBefore = livePgScalar("SELECT pg_has_role(?, 'USAGE')", [$role]);

    expect(fn () => app(AccountManager::class)->drop('live_pgsql', new Username($role), new Host('%')))
        ->toThrow(InsufficientPrivilege::class);

    expect((int) livePgScalar('SELECT COUNT(*) FROM pg_roles WHERE rolname = ?', [$role]))->toBe(1)
        ->and(livePgScalar("SELECT pg_has_role(?, 'USAGE')", [$role]))->toBe($heldBefore)
        ->and(livePgAs($db)->scalar("SELECT tableowner FROM pg_tables WHERE tablename = 'orders'"))->toBe($role);
    livePgClose($db);

    // Let the role go: the superuser removes what the admin could not reach.
    livePgSuper($locked)->statement("REVOKE ALL ON secrets FROM \"{$role}\"");
    DB::purge('live_pg_super_' . $locked);
});

it('gives back the membership it took when a drop fails part way, and keeps what it rolled back', function (): void {
    $db = liveName('shop');
    $role = liveName('app');
    $this->cleanup = [['role', $role], ['database', $db]];
    $admin = livePg('ADMIN_USER');

    app(ProvisioningWizard::class)->provision('live_pgsql', new DbName($db), new Charset('utf8'), new Username($role), new Host('%'), PrivilegeSet::fromPreset(PrivilegePreset::AppStandard), new Password(LIVE_PG_PASSWORD));
    livePgAs($db, $role)->statement('CREATE TABLE orders (id int)');
    livePgClose($db, $role);

    $heldBefore = livePgScalar("SELECT pg_has_role(?, 'USAGE')", [$role]);
    expect($heldBefore)->toBeFalse('the admin must not start with the role\'s privileges, or there is nothing to give back');

    // Fail the release inside $db after membership was taken: a superuser
    // session holds a lock REASSIGN OWNED needs, and the admin's sessions give
    // up waiting after a second.
    livePgSuper('postgres')->statement("ALTER ROLE \"{$admin}\" SET lock_timeout = '1s'");
    $locker = livePgSuper($db);
    $locker->beginTransaction();
    $locker->statement('LOCK TABLE orders IN ACCESS EXCLUSIVE MODE');

    try {
        expect(fn () => app(AccountManager::class)->drop('live_pgsql', new Username($role), new Host('%')))
            ->toThrow(DBConsoleException::class);
    } finally {
        $locker->rollBack();
        DB::purge('live_pg_super_' . $db);
        livePgSuper('postgres')->statement("ALTER ROLE \"{$admin}\" RESET lock_timeout");
    }

    expect((int) livePgScalar('SELECT COUNT(*) FROM pg_roles WHERE rolname = ?', [$role]))->toBe(1)
        ->and(livePgScalar("SELECT pg_has_role(?, 'USAGE')", [$role]))->toBeFalse()
        ->and(livePgAs($db)->scalar("SELECT tableowner FROM pg_tables WHERE tablename = 'orders'"))->toBe($role);
    livePgClose($db);
});

it('rolls back a grant the admin could only partly give, and says what was missing', function (): void {
    $foreign = liveName('foreign');
    $role = liveName('app');
    $this->cleanup = [['role', $role], ['superuser-database', $foreign]];

    // A database the admin did not create: its schema public belongs to the
    // superuser, so the admin holds USAGE through PUBLIC but no grant option.
    // One table there is the admin's, so part of the grant does go through.
    livePgSuper('postgres')->statement("CREATE DATABASE \"{$foreign}\"");
    livePgSuper($foreign)->statement('CREATE TABLE admin_owned (n int)');
    livePgSuper($foreign)->statement('ALTER TABLE admin_owned OWNER TO "' . livePg('ADMIN_USER') . '"');
    DB::purge('live_pg_super_' . $foreign);

    app(AccountManager::class)->create('live_pgsql', new Username($role), new Host('%'), new Password(LIVE_PG_PASSWORD));

    $grant = fn () => app(PrivilegeManager::class)->grant(
        'live_pgsql',
        new Username($role),
        new Host('%'),
        new DbName($foreign),
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
    );

    if (livePgVersion() >= 150000) {
        expect($grant)->toThrow(GrantNotApplied::class, 'CREATE on schema public');

        // All or nothing: the table grant that did go through was rolled back.
        expect(livePgSuper($foreign)->scalar("SELECT has_table_privilege(?, 'admin_owned', 'SELECT')", [$role]))->toBeFalse();
    } else {
        // Before PG15 PUBLIC holds CREATE on public, so the role has every
        // privilege the set asks for, if not from this grant.
        $grant();
        expect(livePgSuper($foreign)->scalar("SELECT has_table_privilege(?, 'admin_owned', 'SELECT')", [$role]))->toBeTrue();
    }
    DB::purge('live_pg_super_' . $foreign);
});
