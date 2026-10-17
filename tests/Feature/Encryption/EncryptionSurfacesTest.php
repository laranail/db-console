<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Enums\TlsStatus;
use Simtabi\Laranail\DBConsole\Encryption\TlsProbe;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;
use Simtabi\Laranail\DBConsole\Doctor\DoctorService;
use Simtabi\Laranail\DBConsole\Encryption\TlsChecker;
use Simtabi\Laranail\DBConsole\Secrets\SecretRotator;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeTlsProbe;

beforeEach(function (): void {
    $this->migrateCatalog();
});

/**
 * Register a remote mysql server whose session the probe reports as given.
 *
 * @param array<string, mixed> $tls
 */
function remoteServer(array $tls, ?bool $encrypted): TlsChecker
{
    config()->set('database.connections.remote_admin', ['driver' => 'mysql', 'host' => '10.0.0.5', 'port' => 3306, 'database' => 'admin', 'username' => 'db_console_admin']);
    config()->set('laranail.db-console.servers.remote', [
        'engine' => 'mysql', 'connection' => 'remote_admin', 'tls' => $tls,
    ]);
    app()->instance(TlsProbe::class, new FakeTlsProbe($encrypted));
    app()->forgetInstance(TlsChecker::class);

    return app(TlsChecker::class);
}

describe('TLS enforcement (section 8, scope 3)', function (): void {
    it('errors on a NON-local server with TLS off', function (): void {
        $checker = remoteServer(['enabled' => false], encrypted: false);

        expect($checker->status('remote'))->toBe(TlsStatus::Off)
            ->and($checker->isLocal('remote'))->toBeFalse()
            ->and($checker->hasBlockingProblem('remote'))->toBeTrue()
            ->and($checker->problems('remote')[0]['severity'])->toBe(Severity::Error);
    });

    it('does not error on a LOCAL server with TLS off', function (): void {
        config()->set('database.connections.local_admin', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306]);
        config()->set('laranail.db-console.servers.local', [
            'engine' => 'mysql', 'connection' => 'local_admin', 'tls' => ['enabled' => false],
        ]);
        app()->instance(TlsProbe::class, new FakeTlsProbe(false));

        $checker = app(TlsChecker::class);

        expect($checker->isLocal('local'))->toBeTrue()
            ->and($checker->hasBlockingProblem('local'))->toBeFalse()
            ->and($checker->problems('local'))->toBe([]);
    });

    it('warns (not errors) on a non-local server with TLS on but unverified', function (): void {
        $checker = remoteServer(['enabled' => true, 'verify' => false], encrypted: true);

        expect($checker->status('remote'))->toBe(TlsStatus::Unverified)
            ->and($checker->hasBlockingProblem('remote'))->toBeFalse()
            ->and($checker->problems('remote')[0]['severity'])->toBe(Severity::Warning);
    });

    it('reports required-and-verified only when the session is encrypted and verification is on', function (): void {
        expect(remoteServer(['enabled' => true, 'verify' => true], encrypted: true)->status('remote'))
            ->toBe(TlsStatus::RequiredOk);
    });
});

describe('TLS is measured from the session, not read from config', function (): void {
    // The defect this guards: tls.enabled=true used to report "required and
    // verified" without looking at the connection, so a plaintext session
    // passed doctor. Read back from config, this test fails.
    it('errors when TLS is configured but the session is plaintext', function (): void {
        $checker = remoteServer(['enabled' => true, 'verify' => true], encrypted: false);

        expect($checker->status('remote'))->toBe(TlsStatus::Off)
            ->and($checker->hasBlockingProblem('remote'))->toBeTrue()
            ->and($checker->problems('remote')[0]['message'])
            ->toContain('(tls.enabled=true) but the admin session is plaintext')
            ->toContain('tls.ca');
    });

    it('warns, rather than vouching for the config, when the session cannot be measured', function (): void {
        $checker = remoteServer(['enabled' => true, 'verify' => true], encrypted: null);

        expect($checker->status('remote'))->toBe(TlsStatus::Unmeasured)
            ->and($checker->hasBlockingProblem('remote'))->toBeFalse()
            ->and($checker->problems('remote')[0]['severity'])->toBe(Severity::Warning);
    });

    it('treats an encrypted session TLS is not required for as unverified', function (): void {
        $checker = remoteServer(['enabled' => false], encrypted: true);

        expect($checker->status('remote'))->toBe(TlsStatus::Unverified)
            ->and($checker->problems('remote')[0]['message'])->toContain('tls.enabled=false');
    });

    it('fails doctor when the configured TLS is not in effect', function (): void {
        remoteServer(['enabled' => true, 'verify' => true], encrypted: false);
        config()->set('laranail.db-console.servers', [
            'remote' => config('laranail.db-console.servers.remote'),
        ]);

        // Doctor checks reachability before TLS, so the connection has to
        // answer: sqlite does, and ignores the remote host that keeps the
        // server out of the local exemption.
        config()->set('database.connections.remote_admin', [
            'driver' => 'sqlite', 'database' => ':memory:', 'host' => 'db.example.com',
        ]);

        $tlsFindings = array_values(array_filter(
            app(DoctorService::class)->run(),
            static fn (DoctorFinding $finding): bool => $finding->check === 'server:remote:tls',
        ));

        expect($tlsFindings)->toHaveCount(1)
            ->and($tlsFindings[0]->severity)->toBe(Severity::Error);
    });
});

describe('locality', function (): void {
    it('reads the host from a url-only connection instead of assuming localhost', function (): void {
        config()->set('database.connections.url_admin', [
            'driver' => 'pgsql', 'url' => 'pgsql://doadmin:secret@db-pg-1.b.db.ondigitalocean.com:25060/defaultdb',
        ]);
        config()->set('laranail.db-console.servers.managed', [
            'engine' => 'pgsql', 'connection' => 'url_admin', 'tls' => ['enabled' => true],
        ]);

        expect(app(TlsChecker::class)->isLocal('managed'))->toBeFalse();
    });

    it('is local only when every read/write host is local', function (): void {
        config()->set('database.connections.split_admin', [
            'driver' => 'mysql',
            'read'   => ['host' => ['127.0.0.1', 'replica.example.com']],
            'write'  => ['host' => ['127.0.0.1']],
        ]);
        config()->set('laranail.db-console.servers.split', [
            'engine' => 'mysql', 'connection' => 'split_admin', 'tls' => ['enabled' => true],
        ]);

        expect(app(TlsChecker::class)->isLocal('split'))->toBeFalse();
    });
});

describe('secrets:rotate re-wraps every stored secret (app_key driver)', function (): void {
    it('re-wraps stored secrets, preserving the value but refreshing the ciphertext', function (): void {
        /** @var SecretVault $vault */
        $vault = app(SecretVault::class);
        $vault->store('server:a', new Secret('admin-secret-a'));
        $vault->store('server:b', new Secret('admin-secret-b'));

        $before = DB::connection('db_console_catalog')->table('db_console_secrets')
            ->where('ref', 'server:a')->value('payload');

        $rotated = app(SecretRotator::class)->rotateAll();

        $after = DB::connection('db_console_catalog')->table('db_console_secrets')
            ->where('ref', 'server:a')->value('payload');

        expect($rotated)->toBe(2)
            // The ciphertext changed (re-wrapped)...
            ->and($after)->not->toBe($before)
            // ...but the revealed value is unchanged.
            ->and($vault->reveal('server:a')->reveal())->toBe('admin-secret-a')
            ->and($vault->reveal('server:b')->reveal())->toBe('admin-secret-b');
    });
});
