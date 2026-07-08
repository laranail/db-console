<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests;

use Illuminate\Foundation\Application;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Simtabi\Laranail\Console\Providers\ConsoleServiceProvider;
use Simtabi\Laranail\DBConsole\Providers\DBConsoleServiceProvider;
use Simtabi\Laranail\Enumerator\EnumeratorServiceProvider;
use Simtabi\Laranail\Package\Tools\Providers\PackageToolsServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        $providers = [
            ConsoleServiceProvider::class,
            PackageToolsServiceProvider::class,
            EnumeratorServiceProvider::class,
            DBConsoleServiceProvider::class,
        ];

        // Spatie's provider (require-dev) so the spatie RBAC driver's tests
        // have its permission config + models available.
        if (class_exists(PermissionServiceProvider::class)) {
            $providers[] = PermissionServiceProvider::class;
        }

        // Sanctum (require-dev) so API-token issuance can be exercised.
        if (class_exists(SanctumServiceProvider::class)) {
            $providers[] = SanctumServiceProvider::class;
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $config = $app['config'];

        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Pin the catalog to a shared in-memory SQLite connection for tests.
        // Defining it here means the provider sees it already registered and
        // reuses it, rather than pointing at a storage_path file.
        $config->set('database.connections.db_console_catalog', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    /**
     * Run the package migrations (they target the catalog connection
     * internally). Call from feature tests that need catalog tables.
     */
    protected function migrateCatalog(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
