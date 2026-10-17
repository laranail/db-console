<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\TlsStatus;
use Simtabi\Laranail\DBConsole\Encryption\TlsChecker;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Exceptions\ServerUnreachable;

/*
 * Against real servers: the `live-tls` job in .github/workflows/tests.yml
 * starts MySQL 8.4 and PostgreSQL 18 with their image defaults, sets the env
 * read below, and runs this group with --fail-on-skipped, so a missing
 * variable fails that job instead of passing it empty. Everywhere else the
 * group skips.
 *
 * The fakes in EncryptionSurfacesTest prove the checker's verdicts; these
 * prove the probe's SQL and the applied options against what a server
 * actually negotiates.
 */

/**
 * @param array<string, mixed> $tls
 */
function liveServer(string $driver, array $tls): TlsChecker
{
    $port = (string) getenv($driver === 'mysql' ? 'DB_CONSOLE_LIVE_MYSQL_PORT' : 'DB_CONSOLE_LIVE_PGSQL_PORT');

    config()->set("database.connections.live_{$driver}", [
        'driver'   => $driver,
        'host'     => '127.0.0.1',
        'port'     => $port,
        'database' => 'db_console_test',
        'username' => $driver === 'mysql' ? 'root' : 'db_console',
        'password' => 'secret',
    ]);
    config()->set("laranail.db-console.servers.live_{$driver}", [
        'engine' => $driver, 'connection' => "live_{$driver}", 'tls' => $tls,
    ]);

    return app(TlsChecker::class);
}

describe('mysql 8.4', function (): void {
    beforeEach(function (): void {
        if (getenv('DB_CONSOLE_LIVE_MYSQL_PORT') === false) {
            $this->markTestSkipped('needs DB_CONSOLE_LIVE_MYSQL_PORT (the live-tls CI job)');
        }
    });

    it('measures a plaintext session as plaintext', function (): void {
        $checker = liveServer('mysql', ['enabled' => false]);

        expect($checker->measure('live_mysql'))->toBeFalse()
            ->and($checker->status('live_mysql'))->toBe(TlsStatus::Off);
    });

    // The defect in production terms: tls.enabled=true with no CA used to
    // report TLS, and the session was plaintext, because pdo_mysql starts TLS
    // only when it has a CA, certificate, key or cipher to use.
    it('catches TLS that is configured but never negotiated', function (): void {
        $checker = liveServer('mysql', ['enabled' => true, 'verify' => false]);

        expect($checker->measure('live_mysql'))->toBeFalse()
            ->and($checker->status('live_mysql'))->toBe(TlsStatus::Off);
    });

    it('measures an encrypted session once the CA is applied', function (): void {
        $ca = (string) getenv('DB_CONSOLE_LIVE_MYSQL_CA');
        expect(is_file($ca))->toBeTrue("DB_CONSOLE_LIVE_MYSQL_CA must name the server's CA file, got '{$ca}'");

        // verify=false: the image's auto-generated certificate does not name
        // 127.0.0.1, so hostname verification would refuse it.
        $checker = liveServer('mysql', ['enabled' => true, 'verify' => false, 'ca' => $ca]);

        expect($checker->measure('live_mysql'))->toBeTrue()
            ->and($checker->status('live_mysql'))->toBe(TlsStatus::Unverified);
    });
})->group('live-tls');

describe('postgresql 18', function (): void {
    beforeEach(function (): void {
        if (getenv('DB_CONSOLE_LIVE_PGSQL_PORT') === false) {
            $this->markTestSkipped('needs DB_CONSOLE_LIVE_PGSQL_PORT (the live-tls CI job)');
        }
    });

    it('measures a plaintext session as plaintext', function (): void {
        $checker = liveServer('pgsql', ['enabled' => false]);

        expect($checker->measure('live_pgsql'))->toBeFalse()
            ->and($checker->status('live_pgsql'))->toBe(TlsStatus::Off);
    });

    // The image runs without SSL. Before the TLS block was applied this
    // connected in plaintext; with sslmode=require it must be refused.
    it('refuses to fall back to plaintext when TLS is required', function (): void {
        $checker = liveServer('pgsql', ['enabled' => true, 'verify' => false]);

        expect(config('database.connections.live_pgsql.sslmode'))->toBeNull()
            ->and($checker->status('live_pgsql'))->toBe(TlsStatus::Unmeasured)
            ->and(config('database.connections.live_pgsql.sslmode'))->toBe('require')
            ->and(fn () => app(ServerRegistry::class)->ensureReachable('live_pgsql'))
            ->toThrow(ServerUnreachable::class);
    });
})->group('live-tls');
