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
use Simtabi\Laranail\DBConsole\Exceptions\UnknownPrivilege;
use Simtabi\Laranail\DBConsole\Engines\GrantVerifyingEngine;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Engines\EmptinessProbingEngine;
use Simtabi\Laranail\DBConsole\Engines\OwnershipReleasingEngine;

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
    'ascii'                         => ['ascii', 'SQL_ASCII'],
    'sql_ascii'                     => ['sql_ascii', 'SQL_ASCII'],
    'latin1'                        => ['latin1', 'LATIN1'],
]);

it('creates a role WITH LOGIN, redacting the password', function () use ($engine): void {
    $statements = $engine->createAccount(new Username('report_user'), new Host('%'), new Password('Xk9$mQ2vLpW7#nR4t!'))->all();

    expect($statements[0]->sql)->toBe('CREATE ROLE "report_user" WITH LOGIN PASSWORD \'Xk9$mQ2vLpW7#nR4t!\'')
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
it('runs schema, table and default-privilege grants inside the target database', function () use ($engine): void {
    $sql = pgScoped($engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite),
        new DbName('analytics'),
        new Username('report_user'),
        new Host('%'),
    ));

    expect($sql)->toBe([
        '[analytics] GRANT CONNECT ON DATABASE "analytics" TO "report_user"',
        '[analytics] GRANT USAGE ON SCHEMA public TO "report_user"',
        '[analytics] GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO "report_user"',
        '[analytics] GRANT SELECT, USAGE ON ALL SEQUENCES IN SCHEMA public TO "report_user"',
        '[analytics] ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO "report_user"',
        '[analytics] ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, USAGE ON SEQUENCES TO "report_user"',
    ]);

    expect(implode(' ', $sql))->not->toContain('SUPERUSER')
        ->and(implode(' ', $sql))->not->toContain('*.*');
});

// PG15 revoked CREATE on schema public from PUBLIC, so an app role that runs
// its own migrations needs it granted explicitly. CREATE is a schema
// privilege, EXECUTE a function one, TEMPORARY a database one: none of them
// may appear in a table grant, where Postgres rejects them.
it('puts each privilege on the object Postgres grants it on', function () use ($engine): void {
    $sql = pgScoped($engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        new DbName('shop_prod'),
        new Username('app'),
        new Host('%'),
    ));

    expect($sql)->toBe([
        '[shop_prod] GRANT CONNECT, TEMPORARY ON DATABASE "shop_prod" TO "app"',
        '[shop_prod] GRANT USAGE, CREATE ON SCHEMA public TO "app"',
        '[shop_prod] GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO "app"',
        '[shop_prod] GRANT SELECT, USAGE ON ALL SEQUENCES IN SCHEMA public TO "app"',
        '[shop_prod] GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO "app"',
        '[shop_prod] ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO "app"',
        '[shop_prod] ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, USAGE ON SEQUENCES TO "app"',
        '[shop_prod] ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO "app"',
    ]);
});

it('grants a read-only set nothing beyond SELECT', function () use ($engine): void {
    $sql = pgScoped($engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly),
        new DbName('analytics'),
        new Username('report_user'),
        new Host('%'),
    ));

    expect($sql)->toBe([
        '[analytics] GRANT CONNECT ON DATABASE "analytics" TO "report_user"',
        '[analytics] GRANT USAGE ON SCHEMA public TO "report_user"',
        '[analytics] GRANT SELECT ON ALL TABLES IN SCHEMA public TO "report_user"',
        '[analytics] GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO "report_user"',
        '[analytics] ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO "report_user"',
        '[analytics] ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON SEQUENCES TO "report_user"',
    ]);
});

it('revokes inside the target database, including the default privileges', function () use ($engine): void {
    expect(pgScoped($engine->revoke(
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        new DbName('shop_prod'),
        new Username('app'),
        new Host('%'),
    )))->toBe([
        '[shop_prod] REVOKE TEMPORARY ON DATABASE "shop_prod" FROM "app"',
        '[shop_prod] REVOKE CREATE ON SCHEMA public FROM "app"',
        '[shop_prod] REVOKE SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public FROM "app"',
        '[shop_prod] REVOKE SELECT, USAGE ON ALL SEQUENCES IN SCHEMA public FROM "app"',
        '[shop_prod] REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA public FROM "app"',
        '[shop_prod] ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM "app"',
        '[shop_prod] ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, USAGE ON SEQUENCES FROM "app"',
        '[shop_prod] ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM "app"',
    ]);
});

// Fail closed: Postgres implements show_view as SELECT, so revoking show_view
// has to remove SELECT, or the role keeps read access it was meant to lose.
it('revokes the shared Postgres privilege when any privilege mapped to it is revoked', function () use ($engine): void {
    expect(pgScoped($engine->revoke(
        PrivilegeSet::custom(['show_view']),
        new DbName('analytics'),
        new Username('report_user'),
        new Host('%'),
    )))->toBe([
        '[analytics] REVOKE SELECT ON ALL TABLES IN SCHEMA public FROM "report_user"',
        '[analytics] ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT ON TABLES FROM "report_user"',
    ]);
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
        ->and($sql)->toContain("has_schema_privilege('app', 'public', p)")
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
