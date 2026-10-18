<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Relay;

use RuntimeException;
use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Servers\ServerDefinition;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Tunnels\PrivateDirectory;
use Simtabi\Laranail\DBConsole\Tunnels\Support\WorkerProcess;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TlsRelayLauncher;

/**
 * When a tunnel gets the MySQL TLS relay, and how it is started.
 *
 * Only where the client could not verify by itself: a MySQL-family server,
 * TLS required and verified, reached over the network from the SSH host.
 * PostgreSQL verifies the real name through libpq's hostaddr; a target that
 * is a socket or loopback on the SSH host never leaves it, and TLS without
 * verification needs no relay to be correct.
 */
final readonly class VerifyingRelay implements TlsRelayLauncher
{
    public static function appliesTo(ServerDefinition $definition, Endpoint $remote, ?string $engineDriver): bool
    {
        return in_array($engineDriver, ['mysql', 'mariadb'], true)
            && $definition->tlsEnabled()
            && $definition->tls['verify']
            && ! $remote->isLocalToItsHost();
    }

    public static function script(): string
    {
        return dirname(__DIR__, 3) . '/resources/bin/mysql-tls-relay.php';
    }

    /**
     * What the relay process is told: where to listen, how to reach the
     * tunnel, and what to verify. A socket-ended tunnel is passed as the
     * socket, which the relay reaches through a loopback pair of its own,
     * since PHP starts TLS only on a tcp:// stream.
     *
     * @return array<string, mixed>
     */
    public static function settings(ServerDefinition $definition, Endpoint $upstream, Endpoint $remote, string $listen, int $timeout): array
    {
        return [
            'listen'   => $listen,
            'upstream' => $upstream->isSocket()
                ? ['socket' => (string) $upstream->socket]
                : ['host' => (string) $upstream->host, 'port' => (int) $upstream->port],
            'peer_name' => (string) $remote->host,
            'tls'       => [
                'cafile'     => $definition->tls['ca'],
                'local_cert' => $definition->tls['cert'],
                'local_pk'   => $definition->tls['key'],
            ],
            'timeout' => $timeout,
        ];
    }

    /**
     * Put the relay in front of an open tunnel, on a socket in the tunnel's
     * private directory, with the tunnel's local end as its upstream.
     *
     * @throws RuntimeException why the relay did not start
     */
    public function launch(
        ServerDefinition $definition,
        Tunnel $tunnel,
        Endpoint $remote,
        PrivateDirectory $directory,
        int $timeout,
    ): RelayedTunnel {
        $worker = WorkerProcess::start(
            self::script(),
            self::settings($definition, $tunnel->local(), $remote, $directory->file('mysql-tls.sock'), $timeout),
            $timeout + 5,
            'MySQL TLS relay',
        );

        return new RelayedTunnel($tunnel, $worker, (string) $remote->host);
    }
}
