<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Throwable;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

final class AuthenticationFailure extends ConnectionException
{
    public static function forServer(string $server, ?Throwable $previous = null): self
    {
        return new self(
            message: "admin credentials were rejected by server '{$server}'",
            userParams: ['server' => $server],
            context: ['server' => $server],
            previous: $previous,
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::AuthenticationFailed;
    }
}
