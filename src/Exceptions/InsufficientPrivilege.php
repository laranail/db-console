<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Throwable;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

final class InsufficientPrivilege extends ConnectionException
{
    public static function forOperation(string $operation, ?Throwable $previous = null): self
    {
        return new self(
            message: "the admin account lacks a privilege needed for '{$operation}'",
            userParams: ['operation' => $operation],
            context: ['operation' => $operation],
            previous: $previous,
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::InsufficientPrivilege;
    }
}
