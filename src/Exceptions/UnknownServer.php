<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

final class UnknownServer extends RegistryException
{
    public function code(): ExceptionCode
    {
        return ExceptionCode::UnknownServer;
    }

    public static function named(string $server): self
    {
        return new self(
            message: "server '{$server}' is not registered",
            userParams: ['server' => $server],
            context: ['server' => $server],
        );
    }
}
