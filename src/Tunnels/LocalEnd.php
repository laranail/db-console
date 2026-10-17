<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels;

/**
 * What the local end of a tunnel is. A loopback port is reachable by every
 * local user on the machine; a socket in a 0700 directory only by this one.
 */
enum LocalEnd: string
{
    case Port = 'port';
    case Socket = 'socket';
}
