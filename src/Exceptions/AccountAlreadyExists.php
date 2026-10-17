<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * The account already exists and the policy refused it (HTTP 409).
 */
final class AccountAlreadyExists extends ResourceAlreadyExists
{
    public function code(): ExceptionCode
    {
        return ExceptionCode::AccountAlreadyExists;
    }

    protected static function describe(string $server, string $target): string
    {
        return "account {$target} already exists on {$server}; refused by the existing-resource policy";
    }
}
