<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;
use Simtabi\Laranail\DBConsole\Enums\ForbiddenPrivilege as ForbiddenPrivilegeEnum;

/**
 * An attempt to grant a self-escalating or server-wide privilege. Hard
 * failure by design; there is no override.
 */
final class ForbiddenPrivilege extends DomainException
{
    public function code(): ExceptionCode
    {
        return ExceptionCode::PrivilegeForbidden;
    }

    public static function forPrivilege(ForbiddenPrivilegeEnum $privilege): self
    {
        return new self(
            message: "privilege '{$privilege->value}' is self-escalating or server-wide and is hard-blocked",
            userParams: ['privilege' => $privilege->label()],
            context: ['privilege' => $privilege->value],
        );
    }
}
