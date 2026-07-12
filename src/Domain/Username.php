<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain;

use Simtabi\Laranail\DBConsole\Exceptions\InvalidIdentifier;

/**
 * A database account name, allow-list validated on construction. 32 is the
 * MySQL user-name limit; engines with different limits re-check.
 */
final readonly class Username
{
    private const string PATTERN = '/^[A-Za-z0-9_]{1,32}$/';

    public const string REQUIREMENT = '1-32 characters of letters, digits, or underscore';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentifier::forValue('username', $value, self::REQUIREMENT);
        }
    }
}
