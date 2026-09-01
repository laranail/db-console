<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Encryption\TlsChecker;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\TlsStatus;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Secrets\SecretRotator;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;

beforeEach(function (): void {
    $this->migrateCatalog();
});

describe('TLS enforcement (section 8, scope 3)', function (): void {
    it('errors on a NON-local server with TLS off', function (): void {
        config()->set('database.connections.remote_admin', ['driver' => 'mysql', 'host' => '10.0.0.5', 'port' => 3306]);
        config()->set('laranail.db-console.servers.remote', [
            'engine' => 'mysql', 'connection' => 'remote_admin', 'tls' => ['enabled' => false],
        ]);

        $checker = app(TlsChecker::class);

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

        $checker = app(TlsChecker::class);

        expect($checker->isLocal('local'))->toBeTrue()
            ->and($checker->hasBlockingProblem('local'))->toBeFalse()
            ->and($checker->problems('local'))->toBe([]);
    });

    it('warns (not errors) on a non-local server with TLS on but unverified', function (): void {
        config()->set('database.connections.remote_admin', ['driver' => 'mysql', 'host' => '10.0.0.5', 'port' => 3306]);
        config()->set('laranail.db-console.servers.remote', [
            'engine' => 'mysql', 'connection' => 'remote_admin', 'tls' => ['enabled' => true, 'verify' => false],
        ]);

        $checker = app(TlsChecker::class);

        expect($checker->status('remote'))->toBe(TlsStatus::Unverified)
            ->and($checker->hasBlockingProblem('remote'))->toBeFalse()
            ->and($checker->problems('remote')[0]['severity'])->toBe(Severity::Warning);
    });

    it('reports required-and-verified when TLS is on and verified', function (): void {
        config()->set('database.connections.remote_admin', ['driver' => 'mysql', 'host' => '10.0.0.5', 'port' => 3306]);
        config()->set('laranail.db-console.servers.remote', [
            'engine' => 'mysql', 'connection' => 'remote_admin', 'tls' => ['enabled' => true, 'verify' => true],
        ]);

        expect(app(TlsChecker::class)->status('remote'))->toBe(TlsStatus::RequiredOk);
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
