<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Tunnels\TunnelSpec;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;

final class FakeTunnel implements Tunnel
{
    public bool $alive = true;

    public bool $closed = false;

    public function __construct(private readonly Endpoint $local, public readonly TunnelSpec $spec) {}

    public function local(): Endpoint
    {
        return $this->local;
    }

    public function isAlive(): bool
    {
        return $this->alive && ! $this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->spec->directory->remove();
    }
}
