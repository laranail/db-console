<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Doctor\TunnelCheck;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;
use Simtabi\Laranail\DBConsole\Doctor\DoctorService;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\TunnelKeys;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeTunnelDriver;
use Simtabi\Laranail\DBConsole\Tunnels\Drivers\OpenSshTunnelDriver;

beforeEach(function (): void {
    $this->driver = new FakeTunnelDriver;
    app()->instance(OpenSshTunnelDriver::class, $this->driver);

    config()->set('database.connections.admin_tunnelled', [
        'driver' => 'pgsql', 'host' => 'db.internal', 'port' => 5432, 'database' => 'defaultdb', 'username' => 'admin',
    ]);
    config()->set('laranail.db-console.servers', [
        'behind-bastion' => [
            'engine' => 'pgsql', 'connection' => 'admin_tunnelled', 'tls' => ['enabled' => false],
            'ssh'    => [
                'enabled' => true, 'host' => 'bastion.example.com', 'user' => 'tunnel',
                'key'     => __FILE__, 'host_key' => TunnelKeys::PINNED,
            ],
        ],
    ]);
});

/**
 * @param list<DoctorFinding> $findings
 *
 * @return array<string, string>
 */
function findingsByCheck(array $findings): array
{
    $byCheck = [];
    foreach ($findings as $finding) {
        $byCheck[$finding->check] = $finding->severity->value . ': ' . $finding->message;
    }

    return $byCheck;
}

it('says nothing about a server without a tunnel', function (): void {
    config()->set('database.connections.direct', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('laranail.db-console.servers.direct', ['engine' => 'sqlite', 'connection' => 'direct']);

    expect(app(TunnelCheck::class)->findings('direct'))->toBe([]);
});

it('reports the driver, the pin and the open tunnel', function (): void {
    $findings = findingsByCheck(app(TunnelCheck::class)->findings('behind-bastion'));

    expect($findings)->toHaveKeys(['server:behind-bastion:ssh-driver', 'server:behind-bastion:ssh-host-key', 'server:behind-bastion:ssh-tunnel'])
        ->and($findings['server:behind-bastion:ssh-host-key'])->toContain(TunnelKeys::PINNED_FINGERPRINT)
        ->and($findings['server:behind-bastion:ssh-tunnel'])->toContain('tunnel@bastion.example.com:22 to db.internal:5432');
});

it('fails doctor on a driver that cannot run, before trying to connect', function (): void {
    $this->driver->unavailable = "the ssh binary 'ssh' is not available";

    $findings = app(DoctorService::class)->checkServer('behind-bastion');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->check)->toBe('server:behind-bastion:ssh-driver')
        ->and($findings[0]->severity)->toBe(Severity::Error)
        ->and($this->driver->opened)->toBe([]);
});

it('fails doctor on a tunnel that will not open, and names why', function (): void {
    $this->driver->failWith = 'Host key verification failed';

    $findings = findingsByCheck(app(DoctorService::class)->checkServer('behind-bastion'));

    expect($findings['server:behind-bastion:ssh-tunnel'])->toStartWith('error:')->toContain('Host key verification failed')
        ->and($findings)->not->toHaveKey('server:behind-bastion:reachable');
});
