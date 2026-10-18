<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Relay;

use RuntimeException;

/**
 * A connection the relay has already refused to the client, with a MySQL
 * error saying why (or the server's own ERR passed on). The relay only
 * closes it.
 */
final class RelayRefusal extends RuntimeException {}
