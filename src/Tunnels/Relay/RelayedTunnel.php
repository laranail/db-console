<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Relay;

use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Tunnels\Support\WorkerProcess;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TerminatesTls;

/**
 * A tunnel with the MySQL TLS relay in front of it. The client connects to
 * the relay's socket; the relay connects through the tunnel.
 */
final class RelayedTunnel implements TerminatesTls, Tunnel
{
    private bool $closed = false;

    public function __construct(
        private readonly Tunnel $tunnel,
        private readonly WorkerProcess $relay,
        private readonly string $verifiedName,
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public function local(): Endpoint
    {
        return $this->relay->local;
    }

    public function isAlive(): bool
    {
        return ! $this->closed && $this->relay->isRunning() && $this->tunnel->isAlive();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->relay->stop();
        $this->tunnel->close();
    }

    public function verifiedName(): string
    {
        return $this->verifiedName;
    }
}
