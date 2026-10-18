<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Tunnels\PrivateDirectory;
use Simtabi\Laranail\DBConsole\Servers\ServerDefinition;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TerminatesTls;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TlsRelayLauncher;

/**
 * Relays nothing: records what it was asked to put a relay in front of, and
 * hands back a tunnel whose local end is a socket in the tunnel's directory.
 */
final class FakeTlsRelayLauncher implements TlsRelayLauncher
{
    /** @var list<array{server: string, upstream: string, name: string}> */
    public array $launched = [];

    public function launch(ServerDefinition $definition, Tunnel $tunnel, Endpoint $remote, PrivateDirectory $directory, int $timeout): Tunnel&TerminatesTls
    {
        $this->launched[] = ['server' => $definition->name, 'upstream' => $tunnel->local()->describe(), 'name' => (string) $remote->host];

        return new readonly class($tunnel, Endpoint::socket($directory->file('mysql-tls.sock')), (string) $remote->host) implements TerminatesTls, Tunnel {
            public function __construct(private Tunnel $tunnel, private Endpoint $local, private string $name) {}

            public function local(): Endpoint
            {
                return $this->local;
            }

            public function isAlive(): bool
            {
                return $this->tunnel->isAlive();
            }

            public function close(): void
            {
                $this->tunnel->close();
            }

            public function verifiedName(): string
            {
                return $this->name;
            }
        };
    }
}
