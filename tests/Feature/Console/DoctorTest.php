<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;
use Simtabi\Laranail\DBConsole\Doctor\DoctorService;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    $this->registerMysqlServer();
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);
});

it('passes doctor against the Docker MySQL stack (minimal admin, reachable)', function (): void {
    $findings = app(DoctorService::class)->run();

    $errors = array_values(array_filter($findings, fn (DoctorFinding $f): bool => $f->isError()));

    expect($errors)->toBe([], 'doctor should pass on the Docker stack; errors: '
        . implode('; ', array_map(fn (DoctorFinding $f): string => $f->message, $errors)));

    // The doctor command exits 0.
    $this->artisan('laranail::db-console.doctor')->assertSuccessful();
});

it('reports the admin as appropriately scoped (not root-like) for the minimal Docker admin', function (): void {
    $findings = app(DoctorService::class)->checkServer('docker-mysql');

    $admin = array_values(array_filter($findings, fn (DoctorFinding $f): bool => str_ends_with($f->check, ':admin')))[0] ?? null;

    expect($admin)->not->toBeNull()
        ->and($admin->isError())->toBeFalse()
        ->and($admin->message)->toContain('not root-like');
});

it('reaches every registered server and reports its capabilities and TLS', function (): void {
    $findings = app(DoctorService::class)->checkServer('docker-mysql');
    $checks = array_map(fn (DoctorFinding $f): string => $f->check, $findings);

    expect($checks)->toContain('server:docker-mysql:reachable')
        ->and($checks)->toContain('server:docker-mysql:tls')
        ->and($checks)->toContain('server:docker-mysql:capabilities')
        ->and($checks)->toContain('server:docker-mysql:admin');
});

it('fails doctor and raises a security alert when the admin is root-like', function (): void {
    // Point at the real root account — doctor must fail loudly.
    $params = $this->mysqlParams();
    $this->skipUnlessReachable('mysql', $params['host'], $params['port'], 'root', 'root-not-for-app-use');

    config()->set('database.connections.root_admin', [
        'driver' => 'mysql',
        'host' => $params['host'],
        'port' => $params['port'],
        'database' => $params['database'],
        'username' => 'root',
        'password' => 'root-not-for-app-use',
        'prefix' => '',
    ]);
    config()->set('laranail.db-console.servers.rootish', [
        'engine' => 'mysql', 'connection' => 'root_admin', 'tls' => ['enabled' => false],
    ]);

    $findings = app(DoctorService::class)->checkServer('rootish');
    $admin = array_values(array_filter($findings, fn (DoctorFinding $f): bool => str_ends_with($f->check, ':admin')))[0] ?? null;

    expect($admin)->not->toBeNull()
        ->and($admin->isError())->toBeTrue()
        ->and($admin->message)->toContain('ROOT-LIKE')
        ->and($admin->remediation)->toContain('db_console_admin');   // ships the exact fix
});
