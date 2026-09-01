<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;
use Throwable;

/**
 * A credential could not be resolved from the vault/KMS at use-time.
 * Treated as high-severity: it may signal misconfiguration or an attack on
 * the secrets path, so it logs at critical and raises a security alert.
 */
final class SecretUnavailable extends SecretsException
{
    public static function forReference(string $ref, string $driver, ?Throwable $previous = null): self
    {
        return new self(
            message: "secret '{$ref}' could not be resolved via the '{$driver}' driver",
            userParams: ['driver' => $driver],
            context: ['ref' => $ref, 'driver' => $driver],
            previous: $previous,
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::SecretUnavailable;
    }
}
