<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Contracts;

/**
 * A tunnel whose local end already does TLS to the database, verified
 * against the database's real name. The client must not start TLS of its own
 * on top: it talks plaintext over the tunnel's private socket.
 */
interface TerminatesTls
{
    /**
     * The name the server's certificate was verified against.
     */
    public function verifiedName(): string;
}
