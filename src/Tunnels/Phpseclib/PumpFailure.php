<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Phpseclib;

use RuntimeException;

/**
 * A failure the pump reports with a specific exit status (a host key that is
 * not the pin, refused authentication).
 */
final class PumpFailure extends RuntimeException {}
