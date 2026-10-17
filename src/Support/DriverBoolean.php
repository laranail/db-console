<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Support;

/**
 * Reads a boolean column the way every database driver returns it: Postgres
 * as a PHP bool, MySQL and SQLite as 1/0, some drivers as 't'/'f'. Anything
 * else is false, so an unexpected value never reads as a grant or a yes.
 */
final class DriverBoolean
{
    public static function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
