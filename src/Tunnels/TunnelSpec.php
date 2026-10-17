<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels;

/**
 * Everything a driver needs to open one tunnel, with the private key already
 * on disk in the tunnel's own directory (or at the configured path).
 */
final readonly class TunnelSpec
{
    public function __construct(
        public string $server,
        public SshSettings $ssh,
        public Endpoint $remote,
        public string $privateKeyPath,
        public PrivateDirectory $directory,
        /** The socket to listen on when the local end is a socket, else null. */
        public ?string $localSocket,
    ) {}
}
