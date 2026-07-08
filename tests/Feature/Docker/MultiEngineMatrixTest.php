<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;
use Simtabi\Laranail\DBConsole\Doctor\DoctorService;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);
    $this->suffix = $this->uniqueSuffix();
});

it('reaches and passes doctor on MySQL, MariaDB, and Postgres together', function (): void {
    $this->registerMysqlServer();
    $this->registerMariadbServer();
    $this->registerPostgresServer();

    foreach (['docker-mysql', 'docker-mariadb', 'docker-postgres'] as $server) {
        $findings = app(DoctorService::class)->checkServer($server);
        $errors = array_values(array_filter($findings, fn (DoctorFinding $f): bool => $f->isError()));

        expect($errors)->toBe([], "{$server} doctor errors: "
            . implode('; ', array_map(fn (DoctorFinding $f): string => $f->message, $errors)));
    }
});

it('keeps engines isolated: creating on MariaDB never touches MySQL or Postgres', function (): void {
    $this->registerMysqlServer();
    $this->registerMariadbServer();
    $this->registerPostgresServer();

    $databases = app(DatabaseManager::class);
    $db = "dbc_iso_{$this->suffix}";

    try {
        $databases->create('docker-mariadb', new DbName($db), new Charset('utf8mb4'));

        // The database exists on MariaDB only, not on MySQL or Postgres.
        expect($databases->exists('docker-mariadb', new DbName($db)))->toBeTrue()
            ->and($databases->exists('docker-mysql', new DbName($db)))->toBeFalse()
            ->and($databases->exists('docker-postgres', new DbName($db)))->toBeFalse();
    } finally {
        try {
            DB::connection('db_console_mariadb')->statement("DROP DATABASE IF EXISTS `{$db}`");
        } catch (Throwable) {
        }
    }
});
