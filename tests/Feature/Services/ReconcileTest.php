<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Events\ReconcileDriftFound;
use Simtabi\Laranail\DBConsole\Models\ManagedDatabase;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Services\ReconcileService;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    $this->registerMysqlServer();
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);

    $this->suffix = $this->uniqueSuffix();
    $this->managed = "dbc_mgd_{$this->suffix}";     // created via DBConsole
    $this->external = "dbc_ext_{$this->suffix}";    // created outside DBConsole
    $this->orphan = "dbc_orphan_{$this->suffix}";   // in catalog, not on server
});

afterEach(function (): void {
    try {
        $conn = DB::connection('db_console_admin');
        foreach ([$this->managed, $this->external] as $db) {
            $conn->statement("DROP DATABASE IF EXISTS `{$db}`");
        }
    } catch (Throwable) {
    }
});

it('reports unmanaged and orphan databases without ever mutating the server', function (): void {
    Event::fake([ReconcileDriftFound::class]);

    $databases = app(DatabaseManager::class);

    // Managed: created via DBConsole (catalog row + live).
    $databases->create('docker-mysql', new DbName($this->managed), new Charset('utf8mb4'));

    // External: created directly on the server, no catalog row → unmanaged.
    DB::connection('db_console_admin')->statement("CREATE DATABASE `{$this->external}`");

    // Orphan: a catalog row whose database does not exist on the server.
    ManagedDatabase::query()->create(['server_name' => 'docker-mysql', 'name' => $this->orphan, 'is_managed' => true]);

    $report = app(ReconcileService::class)->reconcile('docker-mysql');

    expect($report->unmanagedDatabases)->toContain($this->external)
        ->and($report->unmanagedDatabases)->not->toContain($this->managed)
        ->and($report->orphanDatabases)->toContain($this->orphan)
        ->and($report->hasDrift())->toBeTrue();

    // The server was not mutated — the external database still exists, the
    // orphan was NOT created.
    expect($databases->exists('docker-mysql', new DbName($this->external)))->toBeTrue()
        ->and($databases->exists('docker-mysql', new DbName($this->orphan)))->toBeFalse();

    Event::assertDispatched(ReconcileDriftFound::class);
});

it('adopts unmanaged databases into the catalog on request, marked not-managed', function (): void {
    $databases = app(DatabaseManager::class);
    DB::connection('db_console_admin')->statement("CREATE DATABASE `{$this->external}`");

    $report = app(ReconcileService::class)->reconcile('docker-mysql', adopt: true);

    expect($report->adopted)->toBeGreaterThanOrEqual(1);

    $row = ManagedDatabase::query()->where('server_name', 'docker-mysql')->where('name', $this->external)->first();
    expect($row)->not->toBeNull()
        ->and($row->is_managed)->toBeFalse();   // adopted, not created here
});
