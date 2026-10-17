<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\Hosting;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Servers\ServerDefinition;
use Simtabi\Laranail\DBConsole\Exceptions\ServerMisconfigured;

beforeEach(function (): void {
    config()->set('database.connections.admin_a', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('laranail.db-console.accounts.default_host', 'localhost');
});

function hostedServer(?string $hosting, string $engine = 'mysql'): ServerRegistry
{
    $server = ['engine' => $engine, 'connection' => 'admin_a', 'tls' => ['enabled' => false]];
    if ($hosting !== null) {
        $server['hosting'] = $hosting;
    }
    config()->set('laranail.db-console.servers', ['main' => $server]);

    return app(ServerRegistry::class);
}

it('treats a server with no hosting key as self-hosted', function (): void {
    expect(hostedServer(null)->definition('main')->hosting)->toBe(Hosting::SelfHosted);
});

it('reads the hosting a server declares', function (): void {
    expect(hostedServer('digitalocean')->definition('main')->hosting)->toBe(Hosting::DigitalOcean)
        ->and(hostedServer('digitalocean')->hosting('main')->hosting)->toBe(Hosting::DigitalOcean);
});

it('refuses an unknown hosting value instead of assuming self-hosted', function (): void {
    expect(fn (): ServerDefinition => hostedServer('digital-ocean')->definition('main'))
        ->toThrow(ServerMisconfigured::class, 'digitalocean');
});

it('merges the hosting profile into the server\'s capabilities', function (): void {
    $capabilities = hostedServer('digitalocean')->capabilities('main');

    expect($capabilities->hosting?->hosting)->toBe(Hosting::DigitalOcean)
        ->and($capabilities->canCreateDatabase)->toBeTrue()
        ->and($capabilities->toArray())->toMatchArray([
            'hosting'              => 'digitalocean',
            'default_account_host' => '%',
            'provider_backups'     => true,
        ]);
});

it('keeps the engine\'s own capabilities untouched for a self-hosted server', function (): void {
    $registry = hostedServer(null);

    expect($registry->capabilities('main')->toArray())
        ->toBe([...$registry->engine('main')->capabilities()->toArray(), 'hosting' => 'self_hosted', 'default_account_host' => null, 'provider_backups' => false]);
});

it('defaults a new account\'s host from the hosting, then from config', function (): void {
    expect(hostedServer('digitalocean')->defaultAccountHost('main'))->toBe('%')
        ->and(hostedServer(null)->defaultAccountHost('main'))->toBe('localhost')
        ->and(hostedServer('digitalocean', 'pgsql')->defaultAccountHost('main'))->toBe('localhost');
});
