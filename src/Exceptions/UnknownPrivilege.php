<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

final class UnknownPrivilege extends DomainException
{
    public function code(): ExceptionCode
    {
        return ExceptionCode::PrivilegeUnknown;
    }

    public static function forToken(string $token): self
    {
        return new self(
            message: "privilege '{$token}' is not on the allow-list",
            userParams: ['privilege' => $token],
            context: ['privilege' => $token],
        );
    }
}
