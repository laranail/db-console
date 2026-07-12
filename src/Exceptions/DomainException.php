<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

/**
 * Invalid domain input: bad identifiers, weak passwords, privilege
 * violations. Catch this to handle all validation-level failures.
 */
abstract class DomainException extends DBConsoleException {}
