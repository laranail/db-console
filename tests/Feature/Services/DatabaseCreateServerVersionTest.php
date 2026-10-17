<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeProvisioningEngine;

/*
 * Postgres writes LOCALE_PROVIDER only for a server that parses it (15+), so
 * the manager reads the version before it asks the engine for the statement.
 * Without that the engine never knows the version and leaves the provider
 * out on every server, which an ICU cluster refuses for SQL_ASCII.
 */

beforeEach(function (): void {
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);
    FakeProvisioningEngine::install();
    FakeProvisioningEngine::$createdForVersion = null;
});

it('builds the create statement for the version the server reports', function (): void {
    app(DatabaseManager::class)->create('fake', new DbName('legacy'), new Charset('sql_ascii'));

    expect(FakeProvisioningEngine::$createdForVersion)->toBe(150004)
        ->and(FakeProvisioningEngine::count('fake_databases', 'legacy'))->toBe(1);
});
