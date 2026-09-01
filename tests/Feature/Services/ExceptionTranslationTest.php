<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Exceptions\ConnectionException;
use Simtabi\Laranail\DBConsole\Exceptions\ServerUnreachable;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;

/*
 * Exception translation that needs no live server: a dead port proves every
 * driver failure is translated into a DBConsole exception (never a raw
 * PDOException with SQL/credentials). The credential-rejection case, which
 * needs a reachable-but-wrong-password server, lives in the boilerplate's
 * live-server suite.
 */

beforeEach(function (): void {
    Gate::before(fn ($user = null): bool => true);
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
