<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

/**
 * Admin-connection failures: unreachable servers, rejected credentials,
 * under-privileged admin accounts.
 */
abstract class ConnectionException extends DBConsoleException {}
