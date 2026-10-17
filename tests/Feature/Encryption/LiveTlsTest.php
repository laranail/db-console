<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\TlsStatus;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;
use Simtabi\Laranail\DBConsole\Doctor\DoctorService;
use Simtabi\Laranail\DBConsole\Encryption\TlsChecker;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Exceptions\ServerUnreachable;

/*
 * Against real servers: the `live-tls` job in .github/workflows/tests.yml
 * starts four, all reached as a host doctor does not exempt as local:
 *
 *   - MySQL 8.4 with require_secure_transport=ON, and PostgreSQL 18 with
 *     ssl=on and a pg_hba that rejects every non-TLS host line. Both serve a
 *     certificate for that host from a CA the job generates. This is the
 *     shape of a managed service such as DigitalOcean's.
 *   - A MySQL with TLS switched off and a PostgreSQL with no SSL, so the
 *     guard can be pointed at a server that cannot encrypt.
 *
 * Every connection is a restricted admin: the grant DoctorService recommends
 * on MySQL, a CREATEDB CREATEROLE NOSUPERUSER role on PostgreSQL. The job
 * runs this group with --fail-on-skipped, so a missing variable fails it
 * instead of passing it empty. Everywhere else the group skips.
 *
 * The fakes in EncryptionSurfacesTest prove the checker's verdicts; these
 * prove the applied options, the probe's SQL and doctor's findings against
 * what a server actually negotiates or refuses.
 */

function liveTlsEnv(string $name): string
{
    $value = getenv($name);
    expect($value)->toBeString("{$name} must be set by the live-tls CI job");

    return (string) $value;
}

/**
 * Registers a server named `$name` and returns the name.
 *
 * @param 'mysql'|'pgsql' $driver
 * @param array<string, mixed> $tls
 * @param array<string, mixed> $connection extra connection keys, such as sslmode
 */
function liveTlsServer(string $name, string $driver, string $portEnv, array $tls, array $connection = []): string
{
    config()->set("database.connections.{$name}", [
        'driver'   => $driver,
        'host'     => liveTlsEnv('DB_CONSOLE_LIVE_TLS_HOST'),
        'port'     => liveTlsEnv($portEnv),
        'database' => 'db_console_test',
        'username' => liveTlsEnv('DB_CONSOLE_LIVE_TLS_ADMIN_USER'),
        'password' => liveTlsEnv('DB_CONSOLE_LIVE_TLS_ADMIN_PASSWORD'),
        ...$connection,
    ]);
    config()->set("laranail.db-console.servers.{$name}", [
        'engine' => $driver, 'connection' => $name, 'tls' => $tls,
    ]);

    return $name;
}

/**
 * Doctor's findings for one server, keyed by the part after `server:<name>:`.
 *
 * @return array<string, DoctorFinding>
 */
function liveTlsDoctor(string $server): array
{
    $findings = [];
    foreach (app(DoctorService::class)->checkServer($server) as $finding) {
        $findings[substr($finding->check, strlen("server:{$server}:"))] = $finding;
    }

    return $findings;
}

/**
 * Doctor fails a server when any of its findings is an error.
 *
 * @param array<string, DoctorFinding> $findings
 */
function liveTlsDoctorIsRed(array $findings): bool
{
    return array_any($findings, static fn (DoctorFinding $finding): bool => $finding->isError());
}

/**
 * The severity TlsChecker gives the server's TLS posture: the source of
 * doctor's `:tls` finding, or `Info` (doctor's ok) when there is no problem.
 */
function liveTlsSeverity(string $server): Severity
{
    $problems = app(TlsChecker::class)->problems($server);

    return $problems === [] ? Severity::Info : $problems[0]['severity'];
}

/**
 * The control for every "refused" or "red" test: the same server accepts a
 * connection that is configured the way it requires. Without it, a server
 * that is down, or a wrong port, passes every one of those tests.
 *
 * @param 'mysql'|'pgsql' $driver
 * @param array<string, mixed> $tls
 */
function liveTlsExpectAccepts(string $driver, string $portEnv, array $tls): void
{
    $server = liveTlsServer("live_control_{$driver}_" . md5($portEnv . serialize($tls)), $driver, $portEnv, $tls);

    app(ServerRegistry::class)->ensureReachable($server);

    expect(app(TlsChecker::class)->measure($server))->toBeBool();
}

/**
 * The configuration each TLS-enforcing server accepts.
 *
 * @return array<string, mixed>
 */
function liveTlsVerified(): array
{
    return ['enabled' => true, 'verify' => true, 'ca' => liveTlsEnv('DB_CONSOLE_LIVE_TLS_CA')];
}

beforeEach(function (): void {
    if (getenv('DB_CONSOLE_LIVE_TLS_HOST') === false) {
        $this->markTestSkipped('needs DB_CONSOLE_LIVE_TLS_HOST (the live-tls CI job)');
    }
});

it('reaches every server through a host doctor does not exempt as local', function (): void {
    $server = liveTlsServer('live_tls_locality', 'pgsql', 'DB_CONSOLE_LIVE_PGSQL_PLAIN_PORT', ['enabled' => false]);

    expect(app(TlsChecker::class)->isLocal($server))->toBeFalse();
})->group('live-tls');

describe('mysql 8.4 with require_secure_transport=ON', function (): void {
    it('refuses a plaintext session', function (): void {
        liveTlsExpectAccepts('mysql', 'DB_CONSOLE_LIVE_MYSQL_PORT', liveTlsVerified());

        $server = liveTlsServer('live_mysql_tls_off', 'mysql', 'DB_CONSOLE_LIVE_MYSQL_PORT', ['enabled' => false]);

        expect(fn () => app(ServerRegistry::class)->ensureReachable($server))->toThrow(ServerUnreachable::class);
    });

    // Doctor stops at the reachability error, so the `:tls` finding it would
    // add is read from TlsChecker, which builds it.
    it('fails doctor with a TLS error when tls.enabled=false', function (): void {
        liveTlsExpectAccepts('mysql', 'DB_CONSOLE_LIVE_MYSQL_PORT', liveTlsVerified());

        $server = liveTlsServer('live_mysql_tls_disabled', 'mysql', 'DB_CONSOLE_LIVE_MYSQL_PORT', ['enabled' => false]);

        expect(liveTlsSeverity($server))->toBe(Severity::Error)
            ->and(liveTlsDoctorIsRed(liveTlsDoctor($server)))->toBeTrue();
    });

    // pdo_mysql starts TLS only when it has a CA (or a certificate or key),
    // so tls.enabled=true alone still offers plaintext, and the server says no.
    it('refuses tls.enabled=true without a CA, because pdo_mysql never starts TLS', function (): void {
        liveTlsExpectAccepts('mysql', 'DB_CONSOLE_LIVE_MYSQL_PORT', liveTlsVerified());

        $server = liveTlsServer('live_mysql_tls_no_ca', 'mysql', 'DB_CONSOLE_LIVE_MYSQL_PORT', ['enabled' => true, 'verify' => false]);

        expect(fn () => app(ServerRegistry::class)->ensureReachable($server))->toThrow(ServerUnreachable::class)
            ->and(liveTlsDoctorIsRed(liveTlsDoctor($server)))->toBeTrue();
    });

    it('warns that verify=false is encrypted but unverified', function (): void {
        $server = liveTlsServer('live_mysql_tls_unverified', 'mysql', 'DB_CONSOLE_LIVE_MYSQL_PORT', [
            'enabled' => true, 'verify' => false, 'ca' => liveTlsEnv('DB_CONSOLE_LIVE_TLS_CA'),
        ]);
        $doctor = liveTlsDoctor($server);

        expect(app(TlsChecker::class)->measure($server))->toBeTrue()
            ->and(app(TlsChecker::class)->status($server))->toBe(TlsStatus::Unverified)
            ->and($doctor['tls']->severity)->toBe(Severity::Warning)
            ->and(liveTlsDoctorIsRed($doctor))->toBeFalse();
    });

    it('is green with the CA and verify=true', function (): void {
        $server = liveTlsServer('live_mysql_tls_verified', 'mysql', 'DB_CONSOLE_LIVE_MYSQL_PORT', [
            'enabled' => true, 'verify' => true, 'ca' => liveTlsEnv('DB_CONSOLE_LIVE_TLS_CA'),
        ]);
        $doctor = liveTlsDoctor($server);

        expect(app(TlsChecker::class)->status($server))->toBe(TlsStatus::RequiredOk)
            ->and($doctor['tls']->severity)->toBe(Severity::Info)
            ->and($doctor['admin']->severity)->toBe(Severity::Info)
            ->and(liveTlsDoctorIsRed($doctor))->toBeFalse();
    });
})->group('live-tls');

describe('postgresql 18 with ssl=on and hostssl only', function (): void {
    // libpq's own default is sslmode=prefer, so a plaintext session has to be
    // asked for: tls.enabled=false leaves the connection's sslmode alone.
    it('refuses a plaintext session', function (): void {
        liveTlsExpectAccepts('pgsql', 'DB_CONSOLE_LIVE_PGSQL_PORT', liveTlsVerified());

        $server = liveTlsServer('live_pgsql_tls_off', 'pgsql', 'DB_CONSOLE_LIVE_PGSQL_PORT', ['enabled' => false], ['sslmode' => 'disable']);

        expect(fn () => app(ServerRegistry::class)->ensureReachable($server))->toThrow(ServerUnreachable::class);
    });

    // Doctor stops at the reachability error, so the `:tls` finding it would
    // add is read from TlsChecker, which builds it.
    it('fails doctor with a TLS error when tls.enabled=false and the session is plaintext', function (): void {
        liveTlsExpectAccepts('pgsql', 'DB_CONSOLE_LIVE_PGSQL_PORT', liveTlsVerified());

        $server = liveTlsServer('live_pgsql_tls_disabled', 'pgsql', 'DB_CONSOLE_LIVE_PGSQL_PORT', ['enabled' => false], ['sslmode' => 'disable']);

        expect(liveTlsSeverity($server))->toBe(Severity::Error)
            ->and(liveTlsDoctorIsRed(liveTlsDoctor($server)))->toBeTrue();
    });

    // Unlike pdo_mysql, libpq negotiates TLS whenever the server offers it,
    // so tls.enabled=false is still encrypted here. Doctor cannot see that
    // this server refuses plaintext, so it warns that nothing requires TLS.
    it('warns when tls.enabled=false is encrypted only because libpq prefers TLS', function (): void {
        $server = liveTlsServer('live_pgsql_tls_preferred', 'pgsql', 'DB_CONSOLE_LIVE_PGSQL_PORT', ['enabled' => false]);
        $doctor = liveTlsDoctor($server);

        expect(config("database.connections.{$server}.sslmode"))->toBeNull()
            ->and(app(TlsChecker::class)->measure($server))->toBeTrue()
            ->and(app(TlsChecker::class)->status($server))->toBe(TlsStatus::Unverified)
            ->and($doctor['tls']->severity)->toBe(Severity::Warning)
            ->and($doctor['tls']->message)->toContain('does not require it (tls.enabled=false)');
    });

    it('warns that verify=false is encrypted but unverified', function (): void {
        $server = liveTlsServer('live_pgsql_tls_unverified', 'pgsql', 'DB_CONSOLE_LIVE_PGSQL_PORT', ['enabled' => true, 'verify' => false]);
        $doctor = liveTlsDoctor($server);

        expect(config("database.connections.{$server}.sslmode"))->toBe('require')
            ->and(app(TlsChecker::class)->measure($server))->toBeTrue()
            ->and(app(TlsChecker::class)->status($server))->toBe(TlsStatus::Unverified)
            ->and($doctor['tls']->severity)->toBe(Severity::Warning)
            ->and(liveTlsDoctorIsRed($doctor))->toBeFalse();
    });

    it('is green with the CA and verify=true', function (): void {
        $server = liveTlsServer('live_pgsql_tls_verified', 'pgsql', 'DB_CONSOLE_LIVE_PGSQL_PORT', [
            'enabled' => true, 'verify' => true, 'ca' => liveTlsEnv('DB_CONSOLE_LIVE_TLS_CA'),
        ]);
        $doctor = liveTlsDoctor($server);

        expect(app(TlsChecker::class)->status($server))->toBe(TlsStatus::RequiredOk)
            ->and(config("database.connections.{$server}.sslmode"))->toBe('verify-full')
            ->and($doctor['tls']->severity)->toBe(Severity::Info)
            ->and($doctor['admin']->severity)->toBe(Severity::Info)
            ->and(liveTlsDoctorIsRed($doctor))->toBeFalse();
    });
})->group('live-tls');

// The guard from advice section 6: TLS configured against a server that
// cannot encrypt must turn doctor red, never report what was configured.
describe('the guard, against plaintext servers', function (): void {
    it('turns doctor red when MySQL TLS is configured but never negotiated', function (): void {
        $server = liveTlsServer('live_mysql_plain_no_ca', 'mysql', 'DB_CONSOLE_LIVE_MYSQL_PLAIN_PORT', ['enabled' => true, 'verify' => false]);
        $doctor = liveTlsDoctor($server);

        expect(app(TlsChecker::class)->measure($server))->toBeFalse()
            ->and($doctor['tls']->severity)->toBe(Severity::Error)
            ->and($doctor['tls']->message)->toContain('the admin session is plaintext');
    });

    it('turns doctor red when MySQL is given a CA but the server has no TLS', function (): void {
        liveTlsExpectAccepts('mysql', 'DB_CONSOLE_LIVE_MYSQL_PLAIN_PORT', ['enabled' => false]);

        $server = liveTlsServer('live_mysql_plain_ca', 'mysql', 'DB_CONSOLE_LIVE_MYSQL_PLAIN_PORT', [
            'enabled' => true, 'verify' => true, 'ca' => liveTlsEnv('DB_CONSOLE_LIVE_TLS_CA'),
        ]);

        expect(app(TlsChecker::class)->status($server))->not->toBe(TlsStatus::RequiredOk)
            ->and(liveTlsDoctorIsRed(liveTlsDoctor($server)))->toBeTrue();
    });

    it('turns doctor red when PostgreSQL TLS is required of a server without SSL', function (): void {
        liveTlsExpectAccepts('pgsql', 'DB_CONSOLE_LIVE_PGSQL_PLAIN_PORT', ['enabled' => false]);

        $server = liveTlsServer('live_pgsql_plain_tls', 'pgsql', 'DB_CONSOLE_LIVE_PGSQL_PLAIN_PORT', ['enabled' => true, 'verify' => false]);

        expect(fn () => app(ServerRegistry::class)->ensureReachable($server))->toThrow(ServerUnreachable::class)
            ->and(app(TlsChecker::class)->status($server))->toBe(TlsStatus::Unmeasured)
            ->and(liveTlsDoctorIsRed(liveTlsDoctor($server)))->toBeTrue();
    });

    it('reports a reachable plaintext PostgreSQL as a TLS error', function (): void {
        $server = liveTlsServer('live_pgsql_plain_off', 'pgsql', 'DB_CONSOLE_LIVE_PGSQL_PLAIN_PORT', ['enabled' => false]);
        $doctor = liveTlsDoctor($server);

        expect($doctor['reachable']->severity)->toBe(Severity::Info)
            ->and(app(TlsChecker::class)->measure($server))->toBeFalse()
            ->and($doctor['tls']->severity)->toBe(Severity::Error);
    });
})->group('live-tls');
