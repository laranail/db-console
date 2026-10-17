<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Exceptions\ServerUnreachable;
use Simtabi\Laranail\DBConsole\Exceptions\ConnectionException;
use Simtabi\Laranail\DBConsole\Events\OperationFailed as OperationFailedEvent;

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
        'driver'   => 'mysql',
        'host'     => '127.0.0.1',
        'port'     => 59999,   // nothing listening
        'database' => 'x',
        'username' => 'u',
        'password' => 'p',
        'prefix'   => '',
    ]);
    config()->set('laranail.db-console.servers.dead', [
        'engine' => 'mysql', 'connection' => 'db_console_admin', 'tls' => ['enabled' => false],
    ]);

    expect(fn () => app(ServerRegistry::class)->ensureReachable('dead'))
        ->toThrow(ServerUnreachable::class);
});

it('every connection failure is a translated DBConsole ConnectionException, never a raw PDOException', function (): void {
    config()->set('database.connections.db_console_admin', [
        'driver'   => 'mysql', 'host' => '127.0.0.1', 'port' => 59998,
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

// On Postgres the emptiness check before a drop opens a connection to the
// target, so it can fail on its own. A failure there is still a failed drop:
// logged and dispatched like one, not thrown past the audit trail.
it('audits a drop whose emptiness check fails', function (): void {
    Event::fake([OperationFailedEvent::class]);

    config()->set('database.connections.db_console_admin', [
        'driver'   => 'pgsql', 'host' => '127.0.0.1', 'port' => 59997,
        'database' => 'postgres', 'username' => 'u', 'password' => 'p', 'prefix' => '',
    ]);
    config()->set('laranail.db-console.servers.deadpg', [
        'engine' => 'pgsql', 'connection' => 'db_console_admin', 'tls' => ['enabled' => false],
    ]);

    expect(fn () => app(DatabaseManager::class)->drop('deadpg', new DbName('shop_prod')))
        ->toThrow(ConnectionException::class);

    Event::assertDispatched(
        OperationFailedEvent::class,
        fn (OperationFailedEvent $event): bool => $event->operation() === OperationType::DatabaseDrop,
    );
});
