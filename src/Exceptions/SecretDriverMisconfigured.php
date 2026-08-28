<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

final class SecretDriverMisconfigured extends SecretsException
{
    public static function forDriver(string $driver, string $problem): self
    {
        return new self(
            message: "secret driver '{$driver}' is misconfigured: {$problem}",
            userParams: ['driver' => $driver, 'problem' => $problem],
            context: ['driver' => $driver, 'problem' => $problem],
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::SecretDriverMisconfigured;
    }
}
