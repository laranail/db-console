<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;
use Throwable;

final class ServerUnreachable extends ConnectionException
{
    public static function forServer(string $server, ?Throwable $previous = null): self
    {
        return new self(
            message: "server '{$server}' is unreachable over its admin connection",
            userParams: ['server' => $server],
            context: ['server' => $server],
            previous: $previous,
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::ServerUnreachable;
    }
}
