<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\Privilege;
use Simtabi\Laranail\DBConsole\Domain\Statement;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Engines\PostgresEngine;
use Simtabi\Laranail\DBConsole\Engines\SessionEndingEngine;
use Simtabi\Laranail\DBConsole\Exceptions\UnknownPrivilege;
use Simtabi\Laranail\DBConsole\Engines\GrantVerifyingEngine;
use Simtabi\Laranail\DBConsole\Engines\OwnerDelegatingEngine;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Engines\EmptinessProbingEngine;
use Simtabi\Laranail\DBConsole\Engines\OwnershipReleasingEngine;
use Simtabi\Laranail\DBConsole\Engines\DatabaseScopedGrantsEngine;

function pgSql(iterable $list): array
{
    $out = [];
    foreach ($list as $s) {
        $out[] = $s->sql;
    }

    return $out;
}

/**
 * Each statement as "[database] sql", with "[admin]" for a statement that
 * runs on the admin connection's own database. Where a statement runs is
 * half of what it means in Postgres, so the tests pin both.
 *
 * @return list<string>
 */
function pgScoped(iterable $list): array
{
    $out = [];
    foreach ($list as $s) {
        /** @var Statement $s */
        $out[] = '[' . ($s->database ?? 'admin') . '] ' . $s->sql;
    }

    return $out;
}

$engine = new PostgresEngine;

it('declares the Postgres account model honestly (role, no host scoping)', function () use ($engine): void {
    $caps = $engine->capabilities();

    expect($engine->type())->toBe(EngineType::Pgsql)
        ->and($caps->canCreateAccount)->toBeTrue()
        ->and($caps->canScopeAccountsByHost)->toBeFalse()   // host is pg_hba, not DBConsole
        ->and($caps->accountModelNote)->toContain('pg_hba');
});

it('creates a database with double-quoted identifier and encoding', function () use ($engine): void {
    expect(pgSql($engine->createDatabase(new DbName('shop_prod'), new Charset('utf8'))))
        ->toBe(['CREATE DATABASE "shop_prod" ENCODING \'UTF8\'']);
});

// The package default charset is utf8mb4, and four call sites pass it to
// every engine. Postgres has no encoding by that name and refused the
// database outright.
it('translates MySQL charset names into Postgres encodings', function (string $charset, string $encoding) use ($engine): void {
    expect(pgSql($engine->createDatabase(new DbName('shop_prod'), new Charset($charset, 'utf8mb4_unicode_ci'))))
        ->toBe(["CREATE DATABASE \"shop_prod\" ENCODING '{$encoding}'"]);
})->with([
    'utf8mb4 (the package default)' => ['utf8mb4', 'UTF8'],
    'utf8mb3'                       => ['utf8mb3', 'UTF8'],
    'utf8'                          => ['utf8', 'UTF8'],
]);
// ascii, sql_ascii and latin1 translate too, and need template0: see below.

it('creates a role WITH LOGIN, redacting the password', function () use ($engine): void {
    $statements = $engine->createAccount(new Username('report_user'), new Host('%'), new Password('Xk9$mQ2vLpW7#nR4t!'))->all();

    expect($statements[0]->sql)->toStartWith('CREATE ROLE "report_user" WITH LOGIN PASSWORD \'SCRAM-SHA-256$')
        ->and($statements[0]->redacted)->toBe('CREATE ROLE "report_user" WITH LOGIN PASSWORD \'[redacted]\'')
        ->and($statements[0]->redacted)->not->toContain('Xk9');
});

// DigitalOcean and every pooler keep sessions open on a database, and a plain
// DROP DATABASE refuses while any session is connected.
it('drops a database forcibly, terminating its sessions', function () use ($engine): void {
    expect(pgSql($engine->dropDatabase(new DbName('shop_prod'))))
        ->toBe(['DROP DATABASE "shop_prod" WITH (FORCE)']);
});

// DROP ROLE refuses while the role owns objects or holds privileges anywhere.
// The admin takes membership first because REASSIGN/DROP OWNED need the
// privileges of the role, which a CREATEROLE admin does not inherit on PG16+.
it('releases what a role owns before dropping it', function () use ($engine): void {
    expect(pgScoped($engine->dropAccount(new Username('report_user'), new Host('%'))))->toBe([
        '[admin] GRANT "report_user" TO CURRENT_USER',
        '[admin] REASSIGN OWNED BY "report_user" TO CURRENT_USER',
        '[admin] DROP OWNED BY "report_user"',
        '[admin] DROP ROLE "report_user"',
    ]);
});

it('releases ownership inside every database the role has objects in', function () use ($engine): void {
    expect($engine)->toBeInstanceOf(OwnershipReleasingEngine::class);

    $sql = pgScoped($engine->dropAccountReleasing(
        new Username('report_user'),
        new Host('%'),
        [new DbName('shop_prod'), new DbName('analytics')],
    ));

    expect($sql)->toBe([
        '[admin] GRANT "report_user" TO CURRENT_USER',
        '[shop_prod] REASSIGN OWNED BY "report_user" TO CURRENT_USER',
        '[shop_prod] DROP OWNED BY "report_user"',
        '[analytics] REASSIGN OWNED BY "report_user" TO CURRENT_USER',
        '[analytics] DROP OWNED BY "report_user"',
        '[admin] REASSIGN OWNED BY "report_user" TO CURRENT_USER',
        '[admin] DROP OWNED BY "report_user"',
        '[admin] DROP ROLE "report_user"',
    ]);
});

it('finds the databases a role has objects or privileges in from the shared catalog', function () use ($engine): void {
    $sql = pgSql($engine->dependentDatabases(new Username('report_user')));

    expect($sql)->toHaveCount(1)
        ->and($sql[0])->toContain('pg_shdepend')
        ->and($sql[0])->toContain("rolname = 'report_user'")   // a quoted literal, never raw
        ->and($sql[0])->toContain('current_database()');
});

// The grants are the defect that mattered most: GRANT ... ON ALL TABLES IN
// SCHEMA public runs against whatever database the connection is on, which
// was the admin's, so the target database received nothing but CONNECT.
// Every statement, CONNECT included, runs on one connection to the target so
// the whole grant and its verification share a transaction.
/**
 * The statements of a list as one string, for asserting on clauses inside
 * the DO blocks a grant now emits.
 */
function pgAll(iterable $list): string
{
    return implode("\n", pgSql($list));
}

it('runs every statement of a grant inside the target database', function () use ($engine): void {
    $statements = $engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite),
        new DbName('analytics'),
        new Username('report_user'),
        new Host('%'),
    )->all();

    foreach ($statements as $statement) {
        expect($statement->database)->toBe('analytics');
    }

    expect($statements[0]->sql)->toBe('GRANT CONNECT ON DATABASE "analytics" TO "report_user"')
        ->and(pgAll($statements))->not->toContain('SUPERUSER')
        ->and(pgAll($statements))->not->toContain('*.*');
});

// Every user schema the admin can grant on, not only public: an app that keeps
// its tables in `reporting` or `billing` got nothing before.
it('grants on every user schema the admin can grant on, not only public', function () use ($engine): void {
    $sql = pgAll($engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite),
        new DbName('analytics'),
        new Username('report_user'),
        new Host('%'),
    ));

    expect($sql)->toContain("n.nspname !~ '^pg_' AND n.nspname <> 'information_schema'")
        ->and($sql)->toContain("(n.nspname = 'public' OR pg_has_role(n.nspowner, 'USAGE'))")
        ->and($sql)->toContain("EXECUTE format('GRANT USAGE ON SCHEMA %I TO \"report_user\"', s)")
        ->and($sql)->toContain("EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA %I TO \"report_user\"', s)")
        ->and($sql)->toContain("EXECUTE format('GRANT SELECT, USAGE ON ALL SEQUENCES IN SCHEMA %I TO \"report_user\"', s)")
        ->and($sql)->not->toContain('IN SCHEMA public');
});

// Database-wide defaults (no IN SCHEMA) cover schemas created later too, and
// ON SCHEMAS gives the role USAGE on them.
it('sets database-wide default privileges, including on schemas created later', function () use ($engine): void {
    $sql = pgSql($engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly),
        new DbName('analytics'),
        new Username('report_user'),
        new Host('%'),
    ));

    expect($sql)->toContain('ALTER DEFAULT PRIVILEGES GRANT SELECT ON TABLES TO "report_user"')
        ->and($sql)->toContain('ALTER DEFAULT PRIVILEGES GRANT SELECT ON SEQUENCES TO "report_user"')
        ->and($sql)->toContain('ALTER DEFAULT PRIVILEGES GRANT USAGE ON SCHEMAS TO "report_user"');
});

// A default privilege belongs to the role that creates the object. Tables the
// application's own role creates in a later migration were invisible to a
// reader granted through db-console.
it('covers what other roles create later, through their own default privileges', function () use ($engine): void {
    $sql = pgAll($engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly),
        new DbName('analytics'),
        new Username('report_user'),
        new Host('%'),
    ));

    expect($sql)->toContain("EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE %I GRANT SELECT ON TABLES TO \"report_user\"', w)")
        ->and($sql)->toContain("r.rolname NOT IN (current_user, 'report_user')")
        ->and($sql)->toContain('NOT r.rolsuper')
        ->and($sql)->toContain('aclexplode(n.nspacl)')
        ->and($sql)->toContain("a.privilege_type = 'CREATE'")
        ->and($sql)->toContain('c.relowner = r.oid');
});

// The other direction: a writer granted after the readers. The admin's own
// database-wide defaults list every role db-console granted here, so the new
// writer's future objects get the same.
it('gives a new writer\'s future objects to the roles already granted here', function () use ($engine): void {
    $writer = pgAll($engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        new DbName('shop_prod'),
        new Username('app'),
        new Host('%'),
    ));
    $reader = pgAll($engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly),
        new DbName('shop_prod'),
        new Username('report_user'),
        new Host('%'),
    ));

    expect($writer)->toContain('pg_catalog.pg_default_acl')
        ->and($writer)->toContain('acl.defaclnamespace = 0')
        ->and($writer)->toContain("EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE \"app\" GRANT %s ON %s TO %s'")
        ->and($reader)->not->toContain('pg_catalog.pg_default_acl');
});

// PG15 revoked CREATE on schema public from PUBLIC, so an app role that runs
// its own migrations needs it granted explicitly. CREATE is a schema
// privilege, EXECUTE a function one, TEMPORARY a database one: none of them
// may appear in a table grant, where Postgres rejects them.
it('puts each privilege on the object Postgres grants it on', function () use ($engine): void {
    $sql = pgAll($engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        new DbName('shop_prod'),
        new Username('app'),
        new Host('%'),
    ));

    expect($sql)->toContain('GRANT CONNECT, TEMPORARY ON DATABASE "shop_prod" TO "app"')
        ->and($sql)->toContain("EXECUTE format('GRANT USAGE, CREATE ON SCHEMA %I TO \"app\"', s)")
        ->and($sql)->toContain("EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA %I TO \"app\"', s)")
        ->and($sql)->toContain("EXECUTE format('GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA %I TO \"app\"', s)")
        ->and($sql)->toContain('ALTER DEFAULT PRIVILEGES GRANT EXECUTE ON FUNCTIONS TO "app"')
        ->and($sql)->toContain('ALTER DEFAULT PRIVILEGES GRANT USAGE, CREATE ON SCHEMAS TO "app"')
        ->and($sql)->not->toMatch("/GRANT [^']*(CREATE|EXECUTE|TEMPORARY)[^']* ON ALL TABLES/");
});

it('grants a read-only set nothing beyond SELECT', function () use ($engine): void {
    $sql = pgAll($engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly),
        new DbName('analytics'),
        new Username('report_user'),
        new Host('%'),
    ));

    expect($sql)->not->toMatch('/\b(INSERT|UPDATE|DELETE|TRUNCATE|TEMPORARY)\b|GRANT EXECUTE/')
        ->and($sql)->not->toContain('USAGE, CREATE');
});

it('revokes inside the target database, on every schema, including default privileges', function () use ($engine): void {
    $statements = $engine->revoke(
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        new DbName('shop_prod'),
        new Username('app'),
        new Host('%'),
    )->all();

    foreach ($statements as $statement) {
        expect($statement->database)->toBe('shop_prod');
    }

    $sql = pgAll($statements);
    expect($sql)->toContain('REVOKE TEMPORARY ON DATABASE "shop_prod" FROM "app"')
        ->and($sql)->toContain("EXECUTE format('REVOKE CREATE ON SCHEMA %I FROM \"app\"', s)")
        ->and($sql)->toContain("EXECUTE format('REVOKE SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA %I FROM \"app\"', s)")
        ->and($sql)->toContain('ALTER DEFAULT PRIVILEGES REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM "app"')
        ->and($sql)->toContain('ALTER DEFAULT PRIVILEGES REVOKE CREATE ON SCHEMAS FROM "app"')
        // grants made before this release were per schema; clean those too
        ->and($sql)->toContain("EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA %I REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM \"app\"', s)")
        ->and($sql)->toContain("EXECUTE format('ALTER DEFAULT PRIVILEGES FOR ROLE %I REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM \"app\"', w)")
        // CONNECT and schema USAGE stay: every set implies them
        ->and($sql)->not->toContain('REVOKE CONNECT')
        ->and($sql)->not->toContain('REVOKE USAGE ON SCHEMA');
});

// Fail closed: Postgres implements show_view as SELECT, so revoking show_view
// has to remove SELECT, or the role keeps read access it was meant to lose.
it('revokes the shared Postgres privilege when any privilege mapped to it is revoked', function () use ($engine): void {
    $sql = pgAll($engine->revoke(
        PrivilegeSet::custom(['show_view']),
        new DbName('analytics'),
        new Username('report_user'),
        new Host('%'),
    ));

    expect($sql)->toContain("EXECUTE format('REVOKE SELECT ON ALL TABLES IN SCHEMA %I FROM \"report_user\"', s)")
        ->and($sql)->toContain('ALTER DEFAULT PRIVILEGES REVOKE SELECT ON TABLES FROM "report_user"');
});

// Granting on an object another role owns needs that role's privileges. The
// read lists the owners (and the roles that can create objects) the admin
// would have to assume, whether it already holds them, and whether it can.
it('lists the roles a grant has to act as, inside the target database', function () use ($engine): void {
    expect($engine)->toBeInstanceOf(OwnerDelegatingEngine::class);

    $reader = $engine->rolesToAssume(PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly), new DbName('shop_prod'), new Username('report_user'))->all();
    $writer = pgSql($engine->rolesToAssume(PrivilegeSet::fromPreset(PrivilegePreset::AppStandard), new DbName('shop_prod'), new Username('app')))[0];

    expect($reader)->toHaveCount(1)
        ->and($reader[0]->database)->toBe('shop_prod');

    $sql = $reader[0]->sql;
    expect($sql)->toContain('n.nspowner = r.oid')
        ->and($sql)->toContain('c.relowner = r.oid')
        ->and($sql)->toContain('f.proowner = r.oid')
        ->and($sql)->toContain("pg_has_role(r.oid, 'USAGE') AS held")
        ->and($sql)->toContain("pg_has_role(r.oid, 'MEMBER WITH ADMIN OPTION')")
        ->and($sql)->toContain('NOT r.rolsuper')
        ->and($sql)->not->toContain("r.rolname = 'report_user'")
        // a writer's own default privileges are set FOR ROLE it, which needs it
        ->and($writer)->toContain("r.rolname = 'app'");
});

it('classifies every allow-listed privilege, so every preset builds', function () use ($engine): void {
    foreach (PrivilegePreset::cases() as $preset) {
        if ($preset === PrivilegePreset::Custom) {
            continue;
        }

        expect(count($engine->grant(PrivilegeSet::fromPreset($preset), new DbName('d'), new Username('u'), new Host('%'))))
            ->toBeGreaterThan(1);
    }

    expect(count(Privilege::cases()))->toBe(18, 'a new Privilege case needs a Postgres classification');
});

// Unknown used to become SELECT: a typo granted read access to every table.
it('throws on a privilege it has no classification for, rather than guessing', function () use ($engine): void {
    $classify = Closure::bind(fn (string $p): array => $this->classify($p), $engine, PostgresEngine::class);

    expect(fn () => $classify('superpowers'))->toThrow(UnknownPrivilege::class);
});

it('counts user objects inside the target database for the emptiness check', function () use ($engine): void {
    expect($engine)->toBeInstanceOf(EmptinessProbingEngine::class);

    $statements = $engine->countUserObjects(new DbName('shop_prod'))->all();

    expect($statements)->toHaveCount(1)
        ->and($statements[0]->database)->toBe('shop_prod')
        ->and($statements[0]->sql)->toContain('pg_class')
        ->and($statements[0]->sql)->toContain("'pg_catalog'")
        ->and($statements[0]->sql)->toContain("'information_schema'");
});

it('reads whether the admin already has the privileges of a role', function () use ($engine): void {
    expect(pgScoped($engine->holdsPrivilegesOf(new Username('report_user'))))
        ->toBe(["[admin] SELECT pg_has_role('report_user', 'USAGE')"]);
});

it('takes and gives back membership in a role on the admin connection', function () use ($engine): void {
    expect(pgScoped($engine->takeMembership(new Username('report_user'))))->toBe(['[admin] GRANT "report_user" TO CURRENT_USER'])
        ->and(pgScoped($engine->releaseMembership(new Username('report_user'))))->toBe(['[admin] REVOKE "report_user" FROM CURRENT_USER']);
});

// The drop is split where Postgres forces it: DROP ROLE checks the shared
// dependency catalog, which a release still uncommitted in another database's
// session holds on to, so the per-database releases commit first.
it('splits the release by database from the drop on the admin connection', function () use ($engine): void {
    expect(pgScoped($engine->releaseOwnershipIn(new Username('r'), [new DbName('a'), new DbName('b')])))->toBe([
        '[a] REASSIGN OWNED BY "r" TO CURRENT_USER',
        '[a] DROP OWNED BY "r"',
        '[b] REASSIGN OWNED BY "r" TO CURRENT_USER',
        '[b] DROP OWNED BY "r"',
    ])->and(pgScoped($engine->dropReleasedAccount(new Username('r'), new Host('%'))))->toBe([
        '[admin] REASSIGN OWNED BY "r" TO CURRENT_USER',
        '[admin] DROP OWNED BY "r"',
        '[admin] DROP ROLE "r"',
    ]);
});

it('reports whether the admin can connect to each database a role depends on', function () use ($engine): void {
    expect(pgSql($engine->dependentDatabases(new Username('report_user')))[0])
        ->toContain("has_database_privilege(d.datname, 'CONNECT') AS can_connect");
});

// GRANT only warns when the admin lacks the grant option, and grants what it
// can. The check reads the role's effective privileges instead of trusting it.
it('checks every privilege a grant was meant to give, inside the target database', function () use ($engine): void {
    expect($engine)->toBeInstanceOf(GrantVerifyingEngine::class);

    $statements = $engine->missingGrants(
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        new DbName('shop_prod'),
        new Username('app'),
    )->all();

    expect($statements)->toHaveCount(1)
        ->and($statements[0]->database)->toBe('shop_prod');

    $sql = $statements[0]->sql;
    expect($sql)->toContain("has_database_privilege('app', 'shop_prod', p)")
        ->and($sql)->toContain("ARRAY['CONNECT', 'TEMPORARY']")
        ->and($sql)->toContain("has_schema_privilege('app', n.nspname, p)")
        ->and($sql)->toContain("(n.nspname = 'public' OR pg_has_role(n.nspowner, 'USAGE'))")
        ->and($sql)->toContain("ARRAY['USAGE', 'CREATE']")
        ->and($sql)->toContain("has_table_privilege('app', c.oid, p)")
        ->and($sql)->toContain("ARRAY['SELECT', 'INSERT', 'UPDATE', 'DELETE']")
        ->and($sql)->toContain("has_sequence_privilege('app', c.oid, p)")
        ->and($sql)->toContain("has_function_privilege('app', f.oid, p)");
});

it('checks only what the set grants', function () use ($engine): void {
    $sql = pgSql($engine->missingGrants(
        PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly),
        new DbName('analytics'),
        new Username('report_user'),
    ))[0];

    expect($sql)->toContain("ARRAY['CONNECT']")
        ->and($sql)->toContain("ARRAY['USAGE']")
        ->and($sql)->not->toContain('has_function_privilege')
        ->and($sql)->not->toContain("'CREATE'");
});

// information_schema.role_table_grants on the admin connection saw tables in
// the admin's own database only, and no schema or database grants at all, so
// a role provisioned into any other database showed no grants.
it('lists database grants from the shared catalog and object grants inside each database', function () use ($engine): void {
    $sql = pgScoped($engine->showGrantsIn(new Username('report_user'), [new DbName('analytics'), new DbName('shop')]));

    expect($engine)->toBeInstanceOf(DatabaseScopedGrantsEngine::class)
        ->and($sql)->toHaveCount(4)
        ->and($sql[0])->toStartWith('[admin] ')->toContain('pg_catalog.pg_database')->toContain('aclexplode(d.datacl)')
        ->and($sql[1])->toStartWith('[admin] ')
        ->and($sql[2])->toStartWith('[analytics] ')
        ->and($sql[3])->toStartWith('[shop] ');

    foreach ([1, 2, 3] as $i) {
        expect($sql[$i])
            ->toContain('aclexplode(n.nspacl)')         // schemas
            ->toContain('aclexplode(c.relacl)')         // tables, views, sequences
            ->toContain('aclexplode(p.proacl)')         // functions
            ->toContain('aclexplode(da.defaclacl)')     // default privileges
            ->toContain("rolname = 'report_user'")
            ->not->toContain('information_schema');
    }
});

it('lists the grants on the admin connection alone when given no other databases', function () use ($engine): void {
    $sql = pgScoped($engine->showGrants(new Username('report_user'), new Host('%')));

    expect($sql)->toHaveCount(2)
        ->and($sql[0])->toStartWith('[admin] ')->toContain('aclexplode(d.datacl)')
        ->and($sql[1])->toStartWith('[admin] ')->toContain('aclexplode(c.relacl)');
});

it('finds the databases to read grants in the way a drop finds what to release', function () use ($engine): void {
    expect(pgSql($engine->grantDatabases(new Username('report_user'))))
        ->toBe(pgSql($engine->dependentDatabases(new Username('report_user'))));
});

// CREATE DATABASE copies template1, and only template0 may be copied with a
// different encoding. With template1 in UTF8 (every image and managed
// service), LATIN1 and SQL_ASCII failed outright. The C locale is valid for
// every encoding, where the cluster's own (en_US.utf8) is not.
it('creates a database in an encoding other than UTF8 from template0 with the C locale', function (string $charset, string $encoding) use ($engine): void {
    expect(pgSql($engine->createDatabase(new DbName('legacy'), new Charset($charset))))
        ->toBe(["CREATE DATABASE \"legacy\" TEMPLATE template0 ENCODING '{$encoding}' LC_COLLATE 'C' LC_CTYPE 'C'"]);
})->with([
    'latin1'    => ['latin1', 'LATIN1'],
    'ascii'     => ['ascii', 'SQL_ASCII'],
    'sql_ascii' => ['sql_ascii', 'SQL_ASCII'],
    'win1252'   => ['win1252', 'WIN1252'],
]);

// CREATE ROLE ... PASSWORD 'plaintext' puts the password in the statement
// text, which log_statement = 'ddl' and any failed statement
// (log_min_error_statement) write to the server log. A SCRAM verifier is what
// Postgres would store anyway, and the password cannot be recovered from it.
it('sends a SCRAM-SHA-256 verifier, never the password, when creating or rotating a role', function () use ($engine): void {
    $password = 'Xk9$mQ2vLpW7#nR4t!';
    $create = $engine->createAccount(new Username('report_user'), new Host('%'), new Password($password))->all()[0];
    $rotate = $engine->setPassword(new Username('report_user'), new Host('%'), new Password($password))->all()[0];

    foreach ([[$create, 'CREATE ROLE "report_user" WITH LOGIN PASSWORD '], [$rotate, 'ALTER ROLE "report_user" WITH PASSWORD ']] as [$statement, $prefix]) {
        expect($statement->sql)->toStartWith($prefix)
            ->toMatch('/PASSWORD \'SCRAM-SHA-256\$4096:[A-Za-z0-9+\/=]{24}\$[A-Za-z0-9+\/=]{44}:[A-Za-z0-9+\/=]{44}\'$/')
            ->not->toContain('Xk9')
            ->and($statement->redacted)->toBe($prefix . "'[redacted]'");
    }

    // A fresh salt every time: two roles with one password store different verifiers.
    expect($create->sql)->not->toBe($engine->createAccount(new Username('report_user'), new Host('%'), new Password($password))->all()[0]->sql);
});

// DROP DATABASE ... WITH (FORCE) ends only the sessions the admin may end and
// fails if any remain, so the drop first finds out whose sessions they are.
it('reads which roles hold sessions on a database, and whether the admin can end them', function () use ($engine): void {
    expect($engine)->toBeInstanceOf(SessionEndingEngine::class);

    $statements = $engine->sessionRoles(new DbName('shop_prod'))->all();

    expect($statements)->toHaveCount(1)
        ->and($statements[0]->database)->toBeNull();   // pg_stat_activity is cluster-wide

    $sql = $statements[0]->sql;
    expect($sql)->toContain('pg_catalog.pg_stat_activity')
        ->and($sql)->toContain("a.datname = 'shop_prod'")
        ->and($sql)->toContain('a.pid <> pg_backend_pid()')
        ->and($sql)->toContain("pg_has_role('pg_signal_backend', 'USAGE')")
        ->and($sql)->toContain('r.rolsuper AS superuser');
});
