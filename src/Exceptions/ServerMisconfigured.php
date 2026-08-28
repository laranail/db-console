<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

final class ServerMisconfigured extends RegistryException
{
    public static function named(string $server, string $problem): self
    {
        return new self(
            message: "server '{$server}' is misconfigured: {$problem}",
            userParams: ['server' => $server, 'problem' => $problem],
            context: ['server' => $server, 'problem' => $problem],
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::ServerMisconfigured;
    }
}
