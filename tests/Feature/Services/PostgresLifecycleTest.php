<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    $this->registerPostgresServer();
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);

    $this->suffix = $this->uniqueSuffix();
    $this->db = "dbc_pg_{$this->suffix}";
    $this->role = "dbc_pgr_{$this->suffix}";
});

afterEach(function (): void {
    try {
        $conn = DB::connection('db_console_pgsql');
        $conn->statement("DROP DATABASE IF EXISTS \"{$this->db}\"");
        $conn->statement("DROP ROLE IF EXISTS \"{$this->role}\"");
    } catch (Throwable) {
    }
});

it('runs the full create-database + role + grant lifecycle against real Postgres', function (): void {
    $databases = app(DatabaseManager::class);
    $accounts = app(AccountManager::class);
    $privileges = app(PrivilegeManager::class);

    $databases->create('docker-postgres', new DbName($this->db), new Charset('utf8'));
    expect($databases->exists('docker-postgres', new DbName($this->db)))->toBeTrue();

    $created = $accounts->create('docker-postgres', new Username($this->role), new Host('%'));
    expect($created->hasGeneratedPassword())->toBeTrue()
        ->and($accounts->exists('docker-postgres', new Username($this->role), new Host('%')))->toBeTrue();

    // CONNECT grant on the database (role-based; no host scoping).
    $privileges->grant(
        'docker-postgres',
        new Username($this->role),
        new Host('%'),
        new DbName($this->db),
        PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly),
    );

    // Postgres teardown order: drop the database first (removing the CONNECT
    // dependency), then the role drops cleanly.
    $databases->drop('docker-postgres', new DbName($this->db));
    $accounts->drop('docker-postgres', new Username($this->role), new Host('%'));

    expect($accounts->exists('docker-postgres', new Username($this->role), new Host('%')))->toBeFalse()
        ->and($databases->exists('docker-postgres', new DbName($this->db)))->toBeFalse();
});

it('lists databases live from Postgres', function (): void {
    $databases = app(DatabaseManager::class);
    $databases->create('docker-postgres', new DbName($this->db), new Charset('utf8'));

    expect($databases->list('docker-postgres'))->toContain($this->db);
});
