<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

/**
 * Console authorization failures (gate denials).
 */
abstract class AuthorizationException extends DBConsoleException {}
