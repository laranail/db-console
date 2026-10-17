<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;
use Simtabi\Laranail\DBConsole\Doctor\PostgresAdminCheck;

/*
 * The doctor skipped Postgres entirely and reported every admin as fine, a
 * superuser included. The live group runs these against real servers.
 */

/**
 * @return array<string, string>
 */
function pgDoctor(array $profile = [], ?int $port = 5432): array
{
    $findings = (new PostgresAdminCheck)->findings('main', [
        'is_superuser'       => false,
        'can_create_db'      => true,
        'can_create_role'    => true,
        'server_version_num' => 170004,
        ...$profile,
    ], $port);

    $out = [];
    foreach ($findings as $finding) {
        /** @var DoctorFinding $finding */
        $out[$finding->check] = $finding->severity->value;
    }

    return $out;
}

it('passes an admin with CREATEDB and CREATEROLE and nothing more, on 13 or later', function (): void {
    expect(pgDoctor())->toBe([
        'server:main:admin'            => Severity::Info->value,
        'server:main:admin-privileges' => Severity::Info->value,
        'server:main:version'          => Severity::Info->value,
    ]);
});

it('fails a superuser as root-like, with the minimal role as the fix', function (): void {
    $findings = (new PostgresAdminCheck)->findings('main', [
        'is_superuser' => true, 'can_create_db' => true, 'can_create_role' => true, 'server_version_num' => 170004,
    ], 5432);

    expect($findings[0]->check)->toBe('server:main:admin')
        ->and($findings[0]->isError())->toBeTrue()
        ->and($findings[0]->message)->toContain('superuser')
        ->and($findings[0]->remediation)->toContain('CREATEDB CREATEROLE');
});

it('fails an admin that cannot create databases or roles, naming what is missing', function (array $profile, string $missing): void {
    $findings = (new PostgresAdminCheck)->findings('main', [
        'is_superuser' => false, 'can_create_db' => true, 'can_create_role' => true, 'server_version_num' => 170004, ...$profile,
    ], 5432);

    $privileges = array_values(array_filter($findings, fn (DoctorFinding $f): bool => $f->check === 'server:main:admin-privileges'))[0];

    expect($privileges->isError())->toBeTrue()
        ->and($privileges->message)->toContain($missing);
})->with([
    'no CREATEDB'   => [['can_create_db' => false], 'CREATEDB'],
    'no CREATEROLE' => [['can_create_role' => false], 'CREATEROLE'],
    'neither'       => [['can_create_db' => false, 'can_create_role' => false], 'CREATEDB, CREATEROLE'],
]);

// dropDatabase() sends DROP DATABASE ... WITH (FORCE), which 12 and earlier
// reject as a syntax error.
it('fails a server older than 13', function (): void {
    expect(pgDoctor(['server_version_num' => 120022])['server:main:version'])->toBe(Severity::Error->value)
        ->and(pgDoctor(['server_version_num' => 130000])['server:main:version'])->toBe(Severity::Info->value);
});

it('reads the boolean and numeric forms drivers return', function (): void {
    expect(pgDoctor(['is_superuser' => 'f', 'can_create_db' => 't', 'can_create_role' => 'f', 'server_version_num' => '160008']))
        ->toBe([
            'server:main:admin'            => Severity::Info->value,
            'server:main:admin-privileges' => Severity::Error->value,
            'server:main:version'          => Severity::Info->value,
        ]);
});

// A transaction-mode pooler hands each statement to whichever server session
// is free: the grant transaction and the session a scoped statement opens do
// not survive that, and CREATE/DROP DATABASE are refused through some.
it('warns when the admin connects through a known pooler port', function (int $port): void {
    expect(pgDoctor([], $port)['server:main:pooler'] ?? null)->toBe(Severity::Warning->value);
})->with([
    'PgBouncer'         => [6432],
    'Supabase pooler'   => [6543],
    'DigitalOcean pool' => [25061],
]);

it('does not warn about a direct connection', function (?int $port): void {
    expect(pgDoctor([], $port))->not->toHaveKey('server:main:pooler');
})->with([[5432], [25060], [null]]);

// The port alone warned about a direct connection that happens to use 6432,
// and missed a pooler on any other port. inet_server_port() is the port the
// server itself listens on, so a difference means something relays the
// connection.
it('does not warn about a known pooler port the server itself listens on', function (): void {
    expect(pgDoctor(['server_port' => 6432], 6432))->not->toHaveKey('server:main:pooler');
});

it('still warns about a known pooler port the server does not listen on', function (): void {
    expect(pgDoctor(['server_port' => 5432], 6432)['server:main:pooler'])->toBe(Severity::Warning->value);
});

it('notes a connection relayed from a port no known pooler uses', function (): void {
    $findings = (new PostgresAdminCheck)->findings('main', [
        'is_superuser'       => false, 'can_create_db' => true, 'can_create_role' => true,
        'server_version_num' => 170004, 'server_port' => 5432,
    ], 54320);
    $pooler = array_values(array_filter($findings, fn (DoctorFinding $f): bool => $f->check === 'server:main:pooler'))[0];

    expect($pooler->severity)->toBe(Severity::Info)
        ->and($pooler->message)->toContain('54320')->toContain('5432');
});

// PostgreSQL 16 gave CREATEROLE a ceiling: the admin manages only roles it
// holds ADMIN OPTION on, which by default means roles it created. Dropping or
// changing any other login role then fails with "permission denied".
it('warns on 16 and later about login roles the admin cannot administer, naming them', function (): void {
    $findings = (new PostgresAdminCheck)->findings('main', [
        'is_superuser' => false, 'can_create_db' => true, 'can_create_role' => true, 'server_version_num' => 160004,
    ], 5432, ['legacy_app', 'reporting']);
    $roles = array_values(array_filter($findings, fn (DoctorFinding $f): bool => $f->check === 'server:main:role-admin'))[0];

    expect($roles->severity)->toBe(Severity::Warning)
        ->and($roles->message)->toContain('2 login role(s)')->toContain('legacy_app')->toContain('reporting');
});

it('caps the names it lists', function (): void {
    $names = array_map(fn (int $i): string => "role_{$i}", range(1, 25));
    $findings = (new PostgresAdminCheck)->findings('main', [
        'is_superuser' => false, 'can_create_db' => true, 'can_create_role' => true, 'server_version_num' => 170000,
    ], 5432, $names);
    $roles = array_values(array_filter($findings, fn (DoctorFinding $f): bool => $f->check === 'server:main:role-admin'))[0];

    expect($roles->message)->toContain('25 login role(s)')->toContain('role_10')->not->toContain('role_11')->toContain('and 15 more');
});

it('says nothing about role administration before 16, where CREATEROLE covers every non-superuser role', function (): void {
    $findings = (new PostgresAdminCheck)->findings('main', [
        'is_superuser' => false, 'can_create_db' => true, 'can_create_role' => true, 'server_version_num' => 150008,
    ], 5432, ['legacy_app']);

    expect(array_map(fn (DoctorFinding $f): string => $f->check, $findings))->not->toContain('server:main:role-admin');
});

it('says nothing when the admin administers every login role', function (): void {
    expect(pgDoctor(['server_version_num' => 170000]))->not->toHaveKey('server:main:role-admin');
});
