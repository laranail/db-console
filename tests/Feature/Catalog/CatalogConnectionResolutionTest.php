<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Catalog\CatalogConnection;
use Simtabi\Laranail\DBConsole\Models\DbServer;

/*
 * The catalog is host-agnostic: with no connection configured it rides the
 * host app's default database connection (zero infrastructure); with a
 * dedicated name that the host has not defined, DBConsole provisions a private
 * SQLite connection. Both modes are locked here.
 */

it('rides the host default connection when no catalog connection is configured', function (): void {
    // Simulate a host app whose default connection is its own sqlite DB, with
    // NO dedicated catalog configured. Re-run the provider's resolution.
    config()->set('laranail.db-console.catalog.connection');
    config()->set('database.default', 'host_default');
    config()->set('database.connections.host_default', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);

    // Re-resolve (mirrors registerCatalogConnection's write-back).
    $resolved = config('laranail.db-console.catalog.connection') ?: config('database.default');
    config()->set('laranail.db-console.catalog.connection', $resolved);

    expect($resolved)->toBe('host_default');

    // The catalog connection and every catalog model now follow the host
    // default — no separate db_console_catalog connection is required.
    expect(app(CatalogConnection::class)->name())->toBe('host_default')
        ->and((new DbServer)->getConnectionName())->toBe('host_default');

    // Migrations target that connection; the db_console_ tables land there.
    $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    expect(DbServer::query()->count())->toBe(0);   // table exists, empty
});

it('provisions a dedicated SQLite connection when a configured catalog name is undefined', function (): void {
    // A dedicated name the host has NOT defined → the provider synthesizes a
    // sqlite connection for it (isolation / whole-file SQLCipher mode).
    config()->set('laranail.db-console.catalog.connection', 'db_console_catalog');

    $catalog = app(CatalogConnection::class);
    $definition = $catalog->definition();

    expect($catalog->name())->toBe('db_console_catalog')
        ->and($definition['driver'])->toBe('sqlite');
});
