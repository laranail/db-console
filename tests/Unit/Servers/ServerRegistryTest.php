<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Engines\MySqlEngine;
use Simtabi\Laranail\DBConsole\Engines\PostgresEngine;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Exceptions\ServerMisconfigured;
use Simtabi\Laranail\DBConsole\Exceptions\UnknownServer;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;

function registry(): ServerRegistry
{
    return app(ServerRegistry::class);
}

beforeEach(function (): void {
    // Two servers, each on its own sqlite in-memory connection, so resolution
    // and isolation can be tested without a live server.
    config()->set('database.connections.admin_a', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);
    config()->set('database.connections.admin_b', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);
    config()->set('laranail.db-console.servers', [
        'server-a' => ['engine' => 'mysql', 'connection' => 'admin_a', 'tls' => ['enabled' => false]],
        'server-b' => ['engine' => 'pgsql', 'connection' => 'admin_b', 'tls' => ['enabled' => false]],
    ]);
    config()->set('laranail.db-console.default', 'server-a');
});

describe('resolution', function (): void {
    it('lists all registered server names and the default', function (): void {
        expect(registry()->names())->toBe(['server-a', 'server-b'])
            ->and(registry()->default())->toBe('server-a')
            ->and(registry()->has('server-a'))->toBeTrue()
            ->and(registry()->has('ghost'))->toBeFalse();
    });

    it('resolves a server to the right engine and connection', function (): void {
        [$engineA] = registry()->resolve('server-a');
        [$engineB] = registry()->resolve('server-b');

        expect($engineA)->toBeInstanceOf(MySqlEngine::class)
            ->and($engineB)->toBeInstanceOf(PostgresEngine::class)
            ->and(registry()->definition('server-a')->engine)->toBe(EngineType::Mysql)
            ->and(registry()->connection('server-a')->server)->toBe('server-a');
    });

    it('throws UnknownServer for an unregistered name', function (): void {
        registry()->resolve('nope');
    })->throws(UnknownServer::class);

    it('throws ServerMisconfigured on an invalid engine', function (): void {
        config()->set('laranail.db-console.servers.broken', ['engine' => 'oracle', 'connection' => 'admin_a']);
        registry()->resolve('broken');
    })->throws(ServerMisconfigured::class);

    it('throws ServerMisconfigured when the admin connection is undefined', function (): void {
        config()->set('laranail.db-console.servers.noconn', ['engine' => 'mysql', 'connection' => 'does_not_exist']);
        registry()->resolve('noconn');
    })->throws(ServerMisconfigured::class);
});

describe('multi-server isolation (section 5)', function (): void {
    it('resolves each server to a distinct connection, never mixing state', function (): void {
        $reg = registry();
        $connA = $reg->connection('server-a');
        $connB = $reg->connection('server-b');

        expect($connA)->not->toBe($connB)
            ->and($connA->server)->toBe('server-a')
            ->and($connB->server)->toBe('server-b')
            ->and($connA->underlying()->getName())->toBe('admin_a')
            ->and($connB->underlying()->getName())->toBe('admin_b');
    });

    it('caches the resolved pair per request but keeps servers separate', function (): void {
        $reg = registry();

        expect($reg->resolve('server-a'))->toBe($reg->resolve('server-a'))    // cached
            ->and($reg->resolve('server-a')[1]->server)->not
            ->toBe($reg->resolve('server-b')[1]->server);
    });
});
