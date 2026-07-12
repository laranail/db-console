<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

/**
 * Statement-execution failures, including compensating-rollback failures.
 */
abstract class ExecutionException extends DBConsoleException {}
