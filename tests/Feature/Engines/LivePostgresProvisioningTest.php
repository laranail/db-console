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
use Simtabi\Laranail\DBConsole\Models\DbAccount;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\User;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;
use Simtabi\Laranail\DBConsole\Doctor\DoctorService;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Models\ManagedDatabase;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Exceptions\GrantNotApplied;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;
use Simtabi\Laranail\DBConsole\Services\ProvisioningWizard;
use Simtabi\Laranail\DBConsole\Services\Results\GrantEntry;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Exceptions\AccountAlreadyExists;
use Simtabi\Laranail\DBConsole\Exceptions\DatabaseAlreadyExists;
use Simtabi\Laranail\DBConsole\Exceptions\InsufficientPrivilege;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FixedOperatorAssurance;

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

it('shows the grants a role holds inside the database it was provisioned into', function (): void {
    $db = liveName('shop');
    $role = liveName('app');
    $this->cleanup = [['role', $role], ['database', $db]];

    app(ProvisioningWizard::class)->provision('live_pgsql', new DbName($db), new Charset('utf8'), new Username($role), new Host('%'), PrivilegeSet::fromPreset(PrivilegePreset::AppStandard), new Password(LIVE_PG_PASSWORD));
    livePgAs($db)->statement('CREATE TABLE reference_data (code text PRIMARY KEY)');
    livePgClose($db);

    // The role owns a table it created, and inherits a privilege through a
    // group role.
    $group = liveName('readers');
    $this->cleanup[] = ['role', $group];
    livePgAs($db, $role)->statement('CREATE TABLE orders (id int)');
    livePgClose($db, $role);
    livePgAs('postgres')->statement("CREATE ROLE \"{$group}\" NOLOGIN");
    livePgAs($db)->statement('CREATE TABLE audit_log (n int)');
    livePgAs($db)->statement("GRANT SELECT ON audit_log TO \"{$group}\"");
    livePgAs('postgres')->statement("GRANT \"{$group}\" TO \"{$role}\"");
    livePgClose($db);

    $grants = app(PrivilegeManager::class)->showGrants('live_pgsql', new Username($role), new Host('%'));

    // Read on the admin's own database, as before, this list held nothing:
    // every one of these grants lives in $db.
    expect($grants)->toContain("database {$db} {$db} CONNECT")
        ->toContain("table {$db} public.reference_data SELECT")
        ->toContain("table {$db} public.reference_data INSERT")
        // What it owns, what it inherits, and what everyone may do.
        ->toContain("table {$db} public.orders SELECT via owner")
        ->toContain("table {$db} public.orders DELETE via owner")
        ->toContain("table {$db} public.audit_log SELECT via {$group}")
        ->toContain('database postgres postgres CONNECT via PUBLIC');

    // Database-wide since grants stopped setting defaults per schema, and
    // named for the role whose new tables they cover.
    expect(array_filter($grants, static fn (string $line): bool => str_starts_with($line, "default privileges {$db} tables for role ")
        && str_ends_with($line, ' SELECT')))->not->toBeEmpty();

    // From PG15 schema public belongs to the database owner, the admin, so
    // the schema grant lands. Before it the schema is the bootstrap
    // superuser's: the grant gives nothing and the role holds USAGE and
    // CREATE through PUBLIC.
    if (livePgVersion() >= 150000) {
        expect($grants)->toContain("schema {$db} public USAGE")->toContain("schema {$db} public CREATE");
    } else {
        expect($grants)->toContain("schema {$db} public USAGE via PUBLIC")->toContain("schema {$db} public CREATE via PUBLIC")
            ->not->toContain("schema {$db} public USAGE");
    }

    // --direct, through the service: the account's own and what it owns.
    $direct = array_map(
        static fn (GrantEntry $entry): string => $entry->line(),
        app(PrivilegeManager::class)->grants('live_pgsql', new Username($role), new Host('%'), directOnly: true),
    );
    expect($direct)->toContain("table {$db} public.orders SELECT via owner")
        ->not->toContain("table {$db} public.audit_log SELECT via {$group}")
        ->not->toContain('database postgres postgres CONNECT via PUBLIC');
});

it('says which databases it could not read a role\'s grants in', function (): void {
    $locked = liveName('locked');
    $role = liveName('app');
    $this->cleanup = [['superuser-database', $locked], ['role', $role]];

    app(AccountManager::class)->create('live_pgsql', new Username($role), new Host('%'), new Password(LIVE_PG_PASSWORD));
    $super = livePgSuper('postgres');
    $super->statement("CREATE DATABASE \"{$locked}\"");
    $super->statement("REVOKE CONNECT ON DATABASE \"{$locked}\" FROM PUBLIC");
    livePgSuper($locked)->statement('CREATE TABLE secrets (n int)');
    livePgSuper($locked)->statement("GRANT SELECT ON secrets TO \"{$role}\"");
    DB::purge('live_pg_super_' . $locked);

    expect(app(PrivilegeManager::class)->showGrants('live_pgsql', new Username($role), new Host('%')))
        ->toContain("database {$locked} not readable: the admin may not connect to it");

    livePgSuper($locked)->statement("REVOKE ALL ON secrets FROM \"{$role}\"");
    DB::purge('live_pg_super_' . $locked);
});

it('creates a database in each encoding it offers, not only the template\'s', function (string $charset, string $encoding): void {
    $db = liveName('enc');
    $this->cleanup = [['database', $db]];

    app(DatabaseManager::class)->create('live_pgsql', new DbName($db), new Charset($charset));

    expect(livePgScalar('SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = ?', [$db]))->toBe($encoding);

    // Anything but UTF8 is in the C locale, a libc one, even on a cluster
    // whose template0 is ICU (the ICU leg of the live-pgsql job).
    if ($encoding !== 'UTF8') {
        expect(livePgScalar('SELECT datcollate FROM pg_database WHERE datname = ?', [$db]))->toBe('C');
        if (livePgVersion() >= 150000) {
            expect(livePgScalar('SELECT datlocprovider FROM pg_database WHERE datname = ?', [$db]))->toBe('c');
        }
    }
})->with([
    'utf8 (template1 unchanged)' => ['utf8', 'UTF8'],
    'latin1'                     => ['latin1', 'LATIN1'],
    'sql_ascii'                  => ['sql_ascii', 'SQL_ASCII'],
]);

it('creates a UTF8 database in the C locale when asked for the C collation', function (): void {
    $db = liveName('bytes');
    $this->cleanup = [['database', $db]];

    app(DatabaseManager::class)->create('live_pgsql', new DbName($db), new Charset('utf8', 'C'));

    expect(livePgScalar('SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = ?', [$db]))->toBe('UTF8')
        ->and(livePgScalar('SELECT datcollate FROM pg_database WHERE datname = ?', [$db]))->toBe('C');
});

it('stores the SCRAM verifier it sent, and the role logs in with the password behind it', function (): void {
    $role = liveName('app');
    $this->cleanup = [['role', $role]];
    // Generated, so the rotation also covers every symbol Password::generate() uses.
    $rotated = Password::generate();
    $server = 'live_pgsql';

    app(AccountManager::class)->create($server, new Username($role), new Host('%'), new Password(LIVE_PG_PASSWORD));
    $stored = livePgSuper('postgres')->scalar('SELECT rolpassword FROM pg_authid WHERE rolname = ?', [$role]);
    expect($stored)->toStartWith('SCRAM-SHA-256$4096:');

    app(AccountManager::class)->rotatePassword($server, new Username($role), new Host('%'), $rotated);
    expect(livePgSuper('postgres')->scalar('SELECT rolpassword FROM pg_authid WHERE rolname = ?', [$role]))
        ->toStartWith('SCRAM-SHA-256$4096:')->not->toBe($stored);

    $login = function (string $password) use ($role): mixed {
        $name = 'live_pg_login_' . bin2hex(random_bytes(3));
        config()->set("database.connections.{$name}", livePgConnection('postgres', $role, $password));

        try {
            return DB::connection($name)->scalar('SELECT current_user');
        } finally {
            DB::purge($name);
        }
    };

    // The wrong password is refused, so the server is checking passwords at
    // all (a trust rule in pg_hba.conf would let anything in).
    expect($login($rotated->reveal()))->toBe($role)
        ->and(fn (): mixed => $login(LIVE_PG_PASSWORD))->toThrow(QueryException::class, 'password authentication failed');
});

it('passes the restricted admin in the doctor and fails a superuser', function (): void {
    $findings = static function (string $server): array {
        $out = [];
        foreach (app(DoctorService::class)->checkServer($server) as $finding) {
            $out[$finding->check] = $finding->severity;
        }

        return $out;
    };

    expect($findings('live_pgsql'))
        ->toMatchArray([
            'server:live_pgsql:admin'            => Severity::Info,
            'server:live_pgsql:admin-privileges' => Severity::Info,
            'server:live_pgsql:version'          => Severity::Info,
        ])
        ->not->toHaveKey('server:live_pgsql:pooler');

    // A login role the superuser made, which the admin holds no ADMIN OPTION
    // on. From 16 the admin cannot drop or change it; before 16 CREATEROLE
    // covers it.
    $foreign = liveName('foreign');
    livePgSuper('postgres')->statement("CREATE ROLE \"{$foreign}\" LOGIN");

    try {
        $roleAdmin = array_values(array_filter(
            app(DoctorService::class)->checkServer('live_pgsql'),
            static fn (DoctorFinding $finding): bool => $finding->check === 'server:live_pgsql:role-admin',
        ));

        if (livePgVersion() >= 160000) {
            expect($roleAdmin)->toHaveCount(1)
                ->and($roleAdmin[0]->severity)->toBe(Severity::Warning)
                ->and($roleAdmin[0]->message)->toContain($foreign);
        } else {
            expect($roleAdmin)->toBe([]);
        }
    } finally {
        livePgSuper('postgres')->statement("DROP ROLE \"{$foreign}\"");
    }

    config()->set('database.connections.live_pgsql_super', livePgConnection('postgres', livePg('SUPERUSER'), livePg('SUPERUSER_PASSWORD')));
    config()->set('laranail.db-console.servers.live_pgsql_super', [
        'engine' => 'pgsql', 'connection' => 'live_pgsql_super', 'tls' => ['enabled' => false],
    ]);

    expect($findings('live_pgsql_super')['server:live_pgsql_super:admin'])->toBe(Severity::Error);
});

it('grants on schemas other than public, including ones created after the grant', function (): void {
    $db = liveName('multi');
    $reader = liveName('reader');
    $this->cleanup = [['role', $reader], ['database', $db]];

    app(DatabaseManager::class)->create('live_pgsql', new DbName($db), new Charset('utf8'));
    livePgAs($db)->statement('CREATE SCHEMA reporting');
    livePgAs($db)->statement('CREATE TABLE reporting.daily (n int)');
    livePgAs($db)->insert('INSERT INTO reporting.daily (n) VALUES (1)');
    livePgClose($db);

    app(AccountManager::class)->create('live_pgsql', new Username($reader), new Host('%'), new Password(LIVE_PG_PASSWORD));
    app(PrivilegeManager::class)->grant('live_pgsql', new Username($reader), new Host('%'), new DbName($db), PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly));

    // A schema and a table the admin creates after the grant: covered by the
    // database-wide defaults, schemas included.
    livePgAs($db)->statement('CREATE SCHEMA billing');
    livePgAs($db)->statement('CREATE TABLE billing.invoices (n int)');
    livePgAs($db)->insert('INSERT INTO billing.invoices (n) VALUES (2)');
    livePgClose($db);

    $as = livePgAs($db, $reader);
    expect((int) $as->scalar('SELECT n FROM reporting.daily'))->toBe(1)
        ->and((int) $as->scalar('SELECT n FROM billing.invoices'))->toBe(2);
    livePgClose($db, $reader);
});

it('lets a reader see the tables a writer creates later, whichever was granted first', function (): void {
    $db = liveName('shared');
    $writer = liveName('writer');
    $early = liveName('early');
    $late = liveName('late');
    $this->cleanup = [['role', $early], ['role', $late], ['role', $writer], ['database', $db]];
    $accounts = app(AccountManager::class);
    $privileges = app(PrivilegeManager::class);
    $read = PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly);

    app(DatabaseManager::class)->create('live_pgsql', new DbName($db), new Charset('utf8'));

    // A reader granted before any writer exists.
    $accounts->create('live_pgsql', new Username($early), new Host('%'), new Password(LIVE_PG_PASSWORD));
    $privileges->grant('live_pgsql', new Username($early), new Host('%'), new DbName($db), $read);

    // The application's own role, which runs the migrations.
    $accounts->create('live_pgsql', new Username($writer), new Host('%'), new Password(LIVE_PG_PASSWORD));
    $privileges->grant('live_pgsql', new Username($writer), new Host('%'), new DbName($db), PrivilegeSet::fromPreset(PrivilegePreset::AppStandard));
    livePgAs($db, $writer)->statement('CREATE TABLE orders (n int)');
    livePgAs($db, $writer)->insert('INSERT INTO orders (n) VALUES (3)');
    livePgClose($db, $writer);

    // A reader granted after the writer has created tables.
    $accounts->create('live_pgsql', new Username($late), new Host('%'), new Password(LIVE_PG_PASSWORD));
    $privileges->grant('live_pgsql', new Username($late), new Host('%'), new DbName($db), $read);

    // The next migration.
    livePgAs($db, $writer)->statement('CREATE TABLE invoices (n int)');
    livePgAs($db, $writer)->insert('INSERT INTO invoices (n) VALUES (4)');
    livePgClose($db, $writer);

    foreach ([$early, $late] as $reader) {
        $as = livePgAs($db, $reader);
        expect((int) $as->scalar('SELECT n FROM orders'))->toBe(3, "{$reader} reads orders")
            ->and((int) $as->scalar('SELECT n FROM invoices'))->toBe(4, "{$reader} reads invoices");
        livePgClose($db, $reader);
    }

    // The grants acted as the writer and gave the membership back.
    expect(livePgScalar("SELECT pg_has_role(?, 'USAGE')", [$writer]))->toBeFalse();
});

// WITH (FORCE) ends only sessions the admin may end. An application role's
// session used to make the drop fail: a CREATEROLE admin on PG16+ does not
// inherit the roles it creates, so it could not terminate them.
it('ends an application role\'s sessions to drop its database, and gives the membership back', function (): void {
    $db = liveName('busyapp');
    $role = liveName('app');
    $this->cleanup = [['role', $role], ['database', $db]];

    app(ProvisioningWizard::class)->provision('live_pgsql', new DbName($db), new Charset('utf8'), new Username($role), new Host('%'), PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite), new Password(LIVE_PG_PASSWORD));

    $session = livePgAs($db, $role);
    expect((int) $session->scalar('SELECT 1'))->toBe(1);

    app(DatabaseManager::class)->drop('live_pgsql', new DbName($db));
    livePgClose($db, $role);

    expect((int) livePgScalar('SELECT COUNT(*) FROM pg_database WHERE datname = ?', [$db]))->toBe(0)
        ->and(livePgScalar("SELECT pg_has_role(?, 'USAGE')", [$role]))->toBeFalse();
});

it('refuses up front to drop a database a superuser has a session on', function (): void {
    $db = liveName('superbusy');
    $this->cleanup = [['database', $db]];

    app(DatabaseManager::class)->create('live_pgsql', new DbName($db), new Charset('utf8'));
    $super = livePgSuper($db);
    expect((int) $super->scalar('SELECT 1'))->toBe(1);

    try {
        expect(fn () => app(DatabaseManager::class)->drop('live_pgsql', new DbName($db)))
            ->toThrow(InsufficientPrivilege::class, livePg('SUPERUSER'));

        expect((int) livePgScalar('SELECT COUNT(*) FROM pg_database WHERE datname = ?', [$db]))->toBe(1);
    } finally {
        DB::purge('live_pg_super_' . $db);
    }
});

it('warns that the restricted admin cannot force a drop, and passes a superuser', function (): void {
    $findings = static function (string $server): array {
        $out = [];
        foreach (app(DoctorService::class)->checkServer($server) as $finding) {
            $out[$finding->check] = $finding->severity;
        }

        return $out;
    };

    // CREATEDB CREATEROLE does not include pg_signal_backend, so ending
    // another role's session before DROP ... WITH (FORCE) is refused.
    expect($findings('live_pgsql'))->toMatchArray([
        'server:live_pgsql:hosting'     => Severity::Info,
        'server:live_pgsql:forced-drop' => Severity::Warning,
    ]);

    config()->set('database.connections.live_pgsql_super', livePgConnection('postgres', livePg('SUPERUSER'), livePg('SUPERUSER_PASSWORD')));
    config()->set('laranail.db-console.servers.live_pgsql_super', [
        'engine' => 'pgsql', 'connection' => 'live_pgsql_super', 'tls' => ['enabled' => false],
    ]);

    expect($findings('live_pgsql_super')['server:live_pgsql_super:forced-drop'])->toBe(Severity::Info);
});
/*
 * Existing roles and databases (owner decision 2026-10-17): refused by
 * default, adopted only on request, and given a new password only under
 * adopt_and_apply. Whether a password "was applied" is answered by logging
 * in with it, not by reading what db-console recorded.
 */

/**
 * The password the account had before db-console saw it, generated once per
 * run so no literal credential sits in the repository.
 */
function livePgTheirPassword(): string
{
    static $password = null;

    return $password ??= Password::generate()->reveal();
}

/**
 * An authenticated operator whose host reports a confirmation just now: what
 * adopt_and_apply needs on top of its permissions.
 */
function livePgConfirmedOperator(): void
{
    $operator = new User;
    $operator->id = 7;
    test()->actingAs($operator);
    app()->instance(OperatorAssurance::class, FixedOperatorAssurance::confirmedNow());
}

function livePgCanLogIn(string $role, string $password): bool
{
    $name = 'live_pg_login_' . $role . '_' . substr(hash('sha256', $password), 0, 8);
    config()->set("database.connections.{$name}", livePgConnection('postgres', $role, $password));

    try {
        DB::connection($name)->select('SELECT 1');

        return true;
    } catch (Throwable) {
        return false;
    } finally {
        DB::purge($name);
    }
}

it('refuses a role it did not create, and the role keeps its own password', function (): void {
    $role = liveName('theirs');
    $this->cleanup = [['role', $role]];
    livePgSuper('postgres')->statement("CREATE ROLE \"{$role}\" LOGIN PASSWORD '" . livePgTheirPassword() . "'");

    expect(fn () => app(AccountManager::class)->create('live_pgsql', new Username($role), new Host('%'), new Password(LIVE_PG_PASSWORD)))
        ->toThrow(AccountAlreadyExists::class);

    expect(livePgCanLogIn($role, livePgTheirPassword()))->toBeTrue()
        ->and(livePgCanLogIn($role, LIVE_PG_PASSWORD))->toBeFalse()
        ->and(DbAccount::query()->count())->toBe(0);
});

it('adopts a role on request without touching its password', function (): void {
    $role = liveName('adopted');
    $this->cleanup = [['role', $role]];
    livePgAs('postgres')->statement("CREATE ROLE \"{$role}\" LOGIN PASSWORD '" . livePgTheirPassword() . "'");

    $result = app(AccountManager::class)->create('live_pgsql', new Username($role), new Host('%'), new Password(LIVE_PG_PASSWORD), ExistingResourcePolicy::Adopt);

    expect($result->data['adopted'])->toBeTrue()
        ->and(livePgCanLogIn($role, livePgTheirPassword()))->toBeTrue()
        ->and(livePgCanLogIn($role, LIVE_PG_PASSWORD))->toBeFalse()
        ->and(DbAccount::query()->count())->toBe(1);
});

it('gives an adopted role the supplied password under adopt_and_apply', function (): void {
    $role = liveName('applied');
    $this->cleanup = [['role', $role]];
    livePgAs('postgres')->statement("CREATE ROLE \"{$role}\" LOGIN PASSWORD '" . livePgTheirPassword() . "'");

    livePgConfirmedOperator();
    $result = app(AccountManager::class)->create('live_pgsql', new Username($role), new Host('%'), new Password(LIVE_PG_PASSWORD), ExistingResourcePolicy::AdoptAndApply);

    expect($result->data['password_applied'])->toBeTrue()
        ->and(livePgCanLogIn($role, LIVE_PG_PASSWORD))->toBeTrue()
        ->and(livePgCanLogIn($role, livePgTheirPassword()))->toBeFalse();
});

it('refuses an existing database, and adopts it on request', function (): void {
    $db = liveName('existing');
    $this->cleanup = [['database', $db]];
    livePgAs('postgres')->statement("CREATE DATABASE \"{$db}\"");

    expect(fn () => app(DatabaseManager::class)->create('live_pgsql', new DbName($db), new Charset('utf8')))
        ->toThrow(DatabaseAlreadyExists::class);
    expect(ManagedDatabase::query()->count())->toBe(0);

    $result = app(DatabaseManager::class)->create('live_pgsql', new DbName($db), new Charset('utf8'), ExistingResourcePolicy::Adopt);

    expect($result->data['adopted'])->toBeTrue()
        ->and(ManagedDatabase::query()->count())->toBe(1)
        ->and((int) livePgScalar('SELECT COUNT(*) FROM pg_database WHERE datname = ?', [$db]))->toBe(1);
});

it('re-runs the wizard without a password when the operator adopts, keeping the role\'s password', function (): void {
    $db = liveName('rerun');
    $role = liveName('rerun');
    $this->cleanup = [['role', $role], ['database', $db]];
    $provision = fn (?Password $password, ?ExistingResourcePolicy $policy) => app(ProvisioningWizard::class)->provision(
        'live_pgsql',
        new DbName($db),
        new Charset('utf8'),
        new Username($role),
        new Host('%'),
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        $password,
        $policy,
    );

    $provision(new Password(LIVE_PG_PASSWORD), null);

    expect(fn () => $provision(null, null))->toThrow(DatabaseAlreadyExists::class);

    $rerun = $provision(null, ExistingResourcePolicy::Adopt);

    expect($rerun->data['adopted'])->toBeTrue()
        ->and(livePgCanLogIn($role, LIVE_PG_PASSWORD))->toBeTrue();
    livePgAs($db, $role)->statement('CREATE TABLE rerun_check (id integer)');
    livePgClose($db, $role);
});

it('does not call a database empty when it holds only a function or only a schema', function (): void {
    $function = liveName('fnonly');
    $schema = liveName('schemaonly');
    $this->cleanup = [['database', $function], ['database', $schema]];
    $databases = app(DatabaseManager::class);

    $databases->create('live_pgsql', new DbName($function), new Charset('utf8'));
    $databases->create('live_pgsql', new DbName($schema), new Charset('utf8'));
    livePgAs($function)->statement('CREATE FUNCTION ping() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$');
    livePgAs($schema)->statement('CREATE SCHEMA reporting');
    livePgClose($function);
    livePgClose($schema);

    expect($databases->isEmpty('live_pgsql', new DbName($function)))->toBeFalse()
        ->and($databases->isEmpty('live_pgsql', new DbName($schema)))->toBeFalse();

    $databases->rollbackCreatedDatabase('live_pgsql', new DbName($function));
    expect((int) livePgScalar('SELECT COUNT(*) FROM pg_database WHERE datname = ?', [$function]))->toBe(1);
});
