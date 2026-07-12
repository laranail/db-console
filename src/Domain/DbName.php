<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain;

use Simtabi\Laranail\DBConsole\Exceptions\InvalidIdentifier;

/**
 * A database name, allow-list validated on construction. Identifiers cannot
 * be bound as query parameters, so this constructor is the primary
 * injection defense; per-engine quoting is defense in depth on top.
 */
final readonly class DbName
{
    /**
     * Letters, digits, underscore only. No backticks, dots, whitespace, or
     * unicode homoglyphs. 64 is the MySQL identifier limit; engines with
     * lower limits re-check in their own validation.
     */
    private const string PATTERN = '/^[A-Za-z0-9_]{1,64}$/';

    public const string REQUIREMENT = '1-64 characters of letters, digits, or underscore';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentifier::forValue('database name', $value, self::REQUIREMENT);
        }
    }
}
