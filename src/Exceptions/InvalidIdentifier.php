<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

final class InvalidIdentifier extends DomainException
{
    /**
     * @param  string  $kind  what was being validated ("database name", "username", "host")
     * @param  string  $value  the rejected input
     * @param  string  $requirement  the allow-list requirement, in plain words
     */
    public static function forValue(string $kind, string $value, string $requirement): self
    {
        return new self(
            message: "{$kind} '{$value}' is invalid: must be {$requirement}",
            userParams: ['kind' => $kind, 'value' => $value, 'requirement' => $requirement],
            context: ['kind' => $kind, 'value' => $value],
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::IdentifierInvalid;
    }
}
