<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Tunnels\LocalEnd;
use Simtabi\Laranail\DBConsole\Tunnels\SshSettings;
use Simtabi\Laranail\DBConsole\Enums\TunnelDriverType;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\TunnelKeys;

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function sshBlock(array $overrides = []): array
{
    return [
        'host'     => 'bastion.example.com',
        'user'     => 'tunnel',
        'key'      => __FILE__,
        'host_key' => TunnelKeys::PINNED,
        ...$overrides,
    ];
}

it('defaults to openssh on port 22 with a loopback port as the local end', function (): void {
    $ssh = SshSettings::fromArray(sshBlock());

    expect($ssh->driver)->toBe(TunnelDriverType::OpenSsh)
        ->and($ssh->port)->toBe(22)
        ->and($ssh->local)->toBe(LocalEnd::Port)
        ->and($ssh->keepAlive)->toBe(15);
});

it('names the first problem with a block', function (array $overrides, string $message): void {
    expect(fn (): SshSettings => SshSettings::fromArray(sshBlock($overrides)))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown driver'  => [['driver' => 'putty'], "ssh.driver 'putty'"],
    'no host'         => [['host' => ''], 'ssh.host is required'],
    'no user'         => [['user' => null], 'ssh.user is required'],
    'no key'          => [['key' => null], 'exactly one of ssh.key'],
    'two keys'        => [['key_secret' => 'ssh/bastion'], 'exactly one of ssh.key'],
    'no pin'          => [['host_key' => null], 'never trusts a host key on first use'],
    'bad port'        => [['port' => 70000], 'ssh.port must be a port number'],
    'bad local'       => [['local' => 'pipe'], "ssh.local must be 'port' or 'socket'"],
    'relative socket' => [['remote_socket' => 'run/pg.sock'], 'ssh.remote_socket must be an absolute path'],
    'bad remote port' => [['remote_port' => 'x'], 'ssh.remote_port must be a port number'],
]);

it('targets the admin connection host unless told otherwise', function (): void {
    $ssh = SshSettings::fromArray(sshBlock());

    expect($ssh->remote('db.internal', 6543, 5432)->describe())->toBe('db.internal:6543')
        ->and($ssh->remote('db.internal', null, 5432)->describe())->toBe('db.internal:5432')
        ->and(SshSettings::fromArray(sshBlock(['remote_host' => '10.0.0.5', 'remote_port' => 3307]))->remote('db.internal', 3306, 3306)->describe())
        ->toBe('10.0.0.5:3307')
        ->and(SshSettings::fromArray(sshBlock(['remote_socket' => '/var/run/postgresql/.s.PGSQL.5432']))->remote('db.internal', 5432, 5432)->describe())
        ->toBe('unix:/var/run/postgresql/.s.PGSQL.5432');
});

it('refuses a tunnel with nowhere to go', function (): void {
    expect(fn (): Endpoint => SshSettings::fromArray(sshBlock())->remote(null, null, 5432))
        ->toThrow(InvalidArgumentException::class, 'no remote target');
});
