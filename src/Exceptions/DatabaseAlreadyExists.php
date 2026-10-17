<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * The database already exists and the policy refused it (HTTP 409).
 */
final class DatabaseAlreadyExists extends ResourceAlreadyExists
{
    public function code(): ExceptionCode
    {
        return ExceptionCode::DatabaseAlreadyExists;
    }

    protected static function describe(string $server, string $target): string
    {
        return "database {$target} already exists on {$server}; refused by the existing-resource policy";
    }
}
