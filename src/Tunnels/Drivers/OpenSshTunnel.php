<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Drivers;

use Symfony\Component\Process\Process;
use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Tunnels\PrivateDirectory;

/**
 * A running `ssh -N` process. Symfony's Process also stops it when this
 * object is destroyed, so a tunnel never outlives the request that opened it.
 */
final class OpenSshTunnel implements Tunnel
{
    private bool $closed = false;

    public function __construct(
        private readonly Process $process,
        private readonly Endpoint $local,
        private readonly PrivateDirectory $directory,
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public function local(): Endpoint
    {
        return $this->local;
    }

    public function isAlive(): bool
    {
        return ! $this->closed && $this->process->isRunning();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->process->stop(3);
        $this->directory->remove();
    }
}
