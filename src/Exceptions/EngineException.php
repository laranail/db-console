<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

/**
 * Engine-level failures: unsupported operations and statement build errors.
 */
abstract class EngineException extends DBConsoleException {}
