<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Tunnels\TunnelSpec;
use Simtabi\Laranail\DBConsole\Tunnels\SshSettings;
use Simtabi\Laranail\DBConsole\Enums\TunnelDriverType;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Exceptions\TunnelFailed;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TunnelDriver;

/**
 * Opens tunnels that forward nothing, so the registry's wiring can be tested
 * without an SSH server. Each open gets the next loopback port.
 */
final class FakeTunnelDriver implements TunnelDriver
{
    /** @var list<TunnelSpec> */
    public array $opened = [];

    /** @var list<FakeTunnel> */
    public array $tunnels = [];

    public ?string $failWith = null;

    public ?string $unavailable = null;

    private int $port = 40000;

    public function type(): TunnelDriverType
    {
        return TunnelDriverType::OpenSsh;
    }

    public function unavailableReason(SshSettings $ssh): ?string
    {
        return $this->unavailable;
    }

    public function open(TunnelSpec $spec): Tunnel
    {
        if ($this->failWith !== null) {
            $spec->directory->remove();

            throw TunnelFailed::forServer($spec->server, $this->failWith);
        }

        $this->opened[] = $spec;

        $local = $spec->localSocket !== null
            ? Endpoint::socket($spec->localSocket)
            : Endpoint::tcp('127.0.0.1', $this->port++);

        return $this->tunnels[] = new FakeTunnel($local, $spec);
    }
}
