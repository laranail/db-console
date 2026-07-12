<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

/**
 * SecretVault failures: unresolved credentials, misconfigured or insecure
 * drivers.
 */
abstract class SecretsException extends DBConsoleException {}
