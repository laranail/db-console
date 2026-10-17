<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Contracts;

use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;

/**
 * A running SSH tunnel. Closing it stops the forwarder and removes every file
 * it wrote (known_hosts, a key from the secrets driver, a local socket).
 */
interface Tunnel
{
    /**
     * Where the admin connection should connect: a loopback port or a socket.
     */
    public function local(): Endpoint;

    public function isAlive(): bool;

    /**
     * Idempotent: closing a closed tunnel does nothing.
     */
    public function close(): void;
}
