<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Contracts;

use RuntimeException;
use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Servers\ServerDefinition;
use Simtabi\Laranail\DBConsole\Tunnels\PrivateDirectory;

/**
 * Puts a TLS relay in front of an open tunnel. A contract so tests can open
 * relayed tunnels without spawning a process.
 */
interface TlsRelayLauncher
{
    /**
     * @throws RuntimeException why the relay did not start
     */
    public function launch(
        ServerDefinition $definition,
        Tunnel $tunnel,
        Endpoint $remote,
        PrivateDirectory $directory,
        int $timeout,
    ): Tunnel&TerminatesTls;
}
