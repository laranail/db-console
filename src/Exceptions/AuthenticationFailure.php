<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;
use Throwable;

final class AuthenticationFailure extends ConnectionException
{
    public function code(): ExceptionCode
    {
        return ExceptionCode::AuthenticationFailed;
    }

    public static function forServer(string $server, ?Throwable $previous = null): self
    {
        return new self(
            message: "admin credentials were rejected by server '{$server}'",
            userParams: ['server' => $server],
            context: ['server' => $server],
            previous: $previous,
        );
    }
}
