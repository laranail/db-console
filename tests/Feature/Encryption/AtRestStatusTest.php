<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Encryption\AtRestStatusReader;
use Simtabi\Laranail\DBConsole\Enums\AtRestStatus;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    $this->registerMysqlServer();
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);

    $this->suffix = $this->uniqueSuffix();
    $this->db = "dbc_enc_{$this->suffix}";
    app(DatabaseManager::class)->create('docker-mysql', new DbName($this->db), new Charset('utf8mb4'));
});

afterEach(function (): void {
    try {
        DB::connection('db_console_admin')->statement("DROP DATABASE IF EXISTS `{$this->db}`");
    } catch (Throwable) {
    }
});

it('reads a real MySQL database at-rest status (display-only, never mutates)', function (): void {
    $status = app(AtRestStatusReader::class)->read('docker-mysql', new DbName($this->db));

    // A fresh unencrypted database reports not_encrypted; DBConsole only
    // reads — it never enabled anything.
    expect($status)->toBeInstanceOf(AtRestStatus::class)
        ->and($status)->toBe(AtRestStatus::NotEncrypted);
});

it('reports unsupported for engines without a managed at-rest readout', function (): void {
    // SQLite's at-rest story is the catalog's own SQLCipher, not a managed-
    // server readout — so it surfaces as unsupported rather than crashing.
    config()->set('database.connections.sqlite_admin', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('laranail.db-console.servers.sqlite_srv', ['engine' => 'sqlite', 'connection' => 'sqlite_admin', 'tls' => ['enabled' => false]]);

    expect(app(AtRestStatusReader::class)->read('sqlite_srv', new DbName('anything')))
        ->toBe(AtRestStatus::Unsupported);
});
