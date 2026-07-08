<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Exceptions\AuthenticationFailure;
use Simtabi\Laranail\DBConsole\Exceptions\ConnectionException;
use Simtabi\Laranail\DBConsole\Exceptions\ServerUnreachable;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    Gate::before(fn ($user = null): bool => true);
});

it('translates rejected admin credentials into AuthenticationFailure (no raw driver error escapes)', function (): void {
    $params = $this->mysqlParams();
    $this->skipUnlessReachable('mysql', $params['host'], $params['port'], $params['username'], $params['password']);

    // Same reachable host, wrong password.
    config()->set('database.connections.db_console_admin', [
        'driver' => 'mysql',
        'host' => $params['host'],
        'port' => $params['port'],
        'database' => $params['database'],
        'username' => $params['username'],
        'password' => 'definitely-the-wrong-password',
        'prefix' => '',
    ]);
    config()->set('laranail.db-console.servers.bad-creds', [
        'engine' => 'mysql', 'connection' => 'db_console_admin', 'tls' => ['enabled' => false],
    ]);

    try {
        app(DatabaseManager::class)->list('bad-creds');
        $this->fail('expected an authentication failure');
    } catch (AuthenticationFailure $e) {
        expect($e->userMessage())->toContain('bad-creds')
            ->and($e->userMessage())->not->toContain('definitely-the-wrong-password');
    }
});

it('translates an unreachable host into ServerUnreachable', function (): void {
    config()->set('database.connections.db_console_admin', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 59999,   // nothing listening
        'database' => 'x',
        'username' => 'u',
        'password' => 'p',
        'prefix' => '',
    ]);
    config()->set('laranail.db-console.servers.dead', [
        'engine' => 'mysql', 'connection' => 'db_console_admin', 'tls' => ['enabled' => false],
    ]);

    expect(fn () => app(ServerRegistry::class)->ensureReachable('dead'))
        ->toThrow(ServerUnreachable::class);
});

it('every connection failure is a translated DBConsole ConnectionException, never a raw PDOException', function (): void {
    config()->set('database.connections.db_console_admin', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 59998,
        'database' => 'x', 'username' => 'u', 'password' => 'p', 'prefix' => '',
    ]);
    config()->set('laranail.db-console.servers.dead2', [
        'engine' => 'mysql', 'connection' => 'db_console_admin', 'tls' => ['enabled' => false],
    ]);

    try {
        app(DatabaseManager::class)->list('dead2');
        $this->fail('expected a connection exception');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(ConnectionException::class);
    }
});
