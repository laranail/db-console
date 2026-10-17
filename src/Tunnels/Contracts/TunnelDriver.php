<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Contracts;

use Simtabi\Laranail\DBConsole\Tunnels\TunnelSpec;
use Simtabi\Laranail\DBConsole\Tunnels\SshSettings;
use Simtabi\Laranail\DBConsole\Enums\TunnelDriverType;
use Simtabi\Laranail\DBConsole\Exceptions\TunnelFailed;

/**
 * Opens SSH tunnels one way. Every driver refuses a server whose host key is
 * not the pinned one, before it authenticates.
 */
interface TunnelDriver
{
    public function type(): TunnelDriverType;

    /**
     * Why this driver cannot run here (no ssh binary, no phpseclib), or null
     * when it can.
     */
    public function unavailableReason(SshSettings $ssh): ?string;

    /**
     * Open the tunnel and wait until its local end accepts connections.
     *
     * @throws TunnelFailed
     */
    public function open(TunnelSpec $spec): Tunnel;
}
