<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain;

use Simtabi\Laranail\DBConsole\Exceptions\InvalidIdentifier;

/**
 * A character set (and optional collation) for database creation. This is
 * the hard validation floor for what an engine receives; the curated
 * options the wizards offer live in the Enums\Charset / Enums\Collation
 * closed sets.
 */
final readonly class Charset
{
    public const string REQUIREMENT = '1-32 characters of letters, digits, or underscore';

    private const string PATTERN = '/^[A-Za-z0-9_]{1,32}$/';

    public function __construct(public string $value, public ?string $collation = null)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentifier::forValue('character set', $value, self::REQUIREMENT);
        }

        if ($collation !== null && preg_match(self::PATTERN, $collation) !== 1) {
            throw InvalidIdentifier::forValue('collation', $collation, self::REQUIREMENT);
        }
    }
}
