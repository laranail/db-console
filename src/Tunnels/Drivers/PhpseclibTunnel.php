<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Drivers;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\InputStream;
use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Tunnels\PrivateDirectory;

/**
 * A running ForwardPump process. Closing its stdin is how it is told to stop;
 * the stop() after that is only for a pump that does not.
 */
final class PhpseclibTunnel implements Tunnel
{
    private bool $closed = false;

    public function __construct(
        private readonly Process $process,
        private readonly InputStream $input,
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
        $this->input->close();
        $this->process->stop(3);
        $this->directory->remove();
    }
}
