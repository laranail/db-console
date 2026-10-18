<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Tunnels\TunnelSpec;
use Simtabi\Laranail\DBConsole\Tunnels\SshSettings;
use Simtabi\Laranail\DBConsole\Exceptions\TunnelFailed;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Tunnels\PrivateDirectory;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\TunnelKeys;
use Simtabi\Laranail\DBConsole\Tunnels\Drivers\OpenSshTunnelDriver;

/**
 * @param array<string, mixed> $ssh
 */
function openSshSpec(Endpoint $remote, array $ssh = []): TunnelSpec
{
    return new TunnelSpec(
        server: 'primary',
        ssh: SshSettings::fromArray([
            'host' => 'bastion.example.com', 'port' => 2222, 'user' => 'tunnel',
            'key'  => __FILE__, 'host_key' => TunnelKeys::generated(), ...$ssh,
        ]),
        remote: $remote,
        privateKeyPath: __FILE__,
        directory: PrivateDirectory::create(sys_get_temp_dir()),
        localSocket: null,
    );
}

/**
 * @param list<string> $command
 *
 * @return array<string, string>
 */
function sshOptions(array $command): array
{
    $options = [];
    foreach ($command as $index => $argument) {
        if ($argument === '-o') {
            [$key, $value] = explode('=', $command[$index + 1], 2);
            $options[$key] = $value;
        }
    }

    return $options;
}

it('runs ssh hardened: pinned key, no prompts, no agent, no user config', function (): void {
    $command = new OpenSshTunnelDriver()->command(
        openSshSpec(Endpoint::tcp('db.internal', 5432)),
        '/tmp/dbc-1/known_hosts',
        Endpoint::tcp('127.0.0.1', 41000),
    );

    expect(array_slice($command, 0, 4))->toBe(['ssh', '-N', '-F', '/dev/null'])
        ->and(sshOptions($command))->toMatchArray([
            'BatchMode'             => 'yes',
            'StrictHostKeyChecking' => 'yes',
            'UserKnownHostsFile'    => '/tmp/dbc-1/known_hosts',
            'GlobalKnownHostsFile'  => '/dev/null',
            'IdentitiesOnly'        => 'yes',
            'IdentityFile'          => __FILE__,
            'IdentityAgent'         => 'none',
            'ExitOnForwardFailure'  => 'yes',
            'ServerAliveInterval'   => '15',
            'StreamLocalBindMask'   => '0177',
        ])
        ->and(implode(' ', $command))->toContain('-p 2222 -l tunnel -L 127.0.0.1:41000:db.internal:5432 -- bastion.example.com');
});

it('forwards to a remote socket, and from a local one', function (): void {
    $driver = new OpenSshTunnelDriver;

    $toSocket = $driver->command(openSshSpec(Endpoint::socket('/var/run/postgresql/.s.PGSQL.5432')), '/k', Endpoint::tcp('127.0.0.1', 41000));
    $fromSocket = $driver->command(openSshSpec(Endpoint::tcp('db.internal', 3306)), '/k', Endpoint::socket('/tmp/dbc-1/db.sock'));

    expect($toSocket)->toContain('127.0.0.1:41000:/var/run/postgresql/.s.PGSQL.5432')
        ->and($fromSocket)->toContain('/tmp/dbc-1/db.sock:db.internal:3306');
});

it('brackets an IPv6 target', function (): void {
    $command = new OpenSshTunnelDriver()->command(openSshSpec(Endpoint::tcp('::1', 5432)), '/k', Endpoint::tcp('127.0.0.1', 41000));

    expect($command)->toContain('127.0.0.1:41000:[::1]:5432');
});

it('reports a forwarder that cannot start as TunnelFailed, and cleans up', function (): void {
    $spec = openSshSpec(Endpoint::tcp('db.internal', 5432), ['binary' => '/nonexistent/ssh']);

    expect(fn (): Tunnel => new OpenSshTunnelDriver()->open($spec))->toThrow(TunnelFailed::class);

    expect(is_dir($spec->directory->path))->toBeFalse();
});

it('reports a missing binary as unavailable', function (): void {
    $spec = openSshSpec(Endpoint::tcp('db.internal', 5432), ['binary' => '/nonexistent/ssh']);

    expect(new OpenSshTunnelDriver()->unavailableReason($spec->ssh))->toContain('/nonexistent/ssh');
});
