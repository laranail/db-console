<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    $this->registerMysqlServer();
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);

    $this->db = 'dbc_cmd_' . $this->uniqueSuffix();
    app(DatabaseManager::class)->create('docker-mysql', new DbName($this->db), new Charset('utf8mb4'));
});

afterEach(function (): void {
    try {
        DB::connection('db_console_admin')->statement("DROP DATABASE IF EXISTS `{$this->db}`");
    } catch (Throwable) {
    }
});

it('registers under the laranail::db-console.db:list name and the db-console:db:list alias', function (): void {
    $names = array_keys(Artisan::all());

    expect($names)->toContain('laranail::db-console.db:list')
        ->and($names)->toContain('db-console:db:list');
});

it('lists live databases through the command', function (): void {
    $this->artisan('laranail::db-console.db:list', ['--server' => 'docker-mysql'])
        ->assertSuccessful();

    // The alias runs the same command.
    $this->artisan('db-console:db:list', ['--server' => 'docker-mysql'])
        ->assertSuccessful();
});
