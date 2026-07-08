<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain;

use Simtabi\Laranail\DBConsole\Exceptions\InvalidIdentifier;

/**
 * A host scope for an account ('localhost', '%', '10.0.%', a hostname or
 * IPv4 address). Allow-list validated: letters, digits, underscore, dot,
 * hyphen, and the % wildcard — never quotes, backticks, whitespace, or
 * statement metacharacters. IPv6 hosts are not supported in v1.
 */
final readonly class Host
{
    private const string PATTERN = '/^[A-Za-z0-9_.%\-]{1,255}$/';

    public const string REQUIREMENT = '1-255 characters of letters, digits, underscore, dot, hyphen, or the % wildcard';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentifier::forValue('host', $value, self::REQUIREMENT);
        }
    }

    public function isLocal(): bool
    {
        return in_array(strtolower($this->value), ['localhost', '127.0.0.1', '::1'], true);
    }

    public function isWildcard(): bool
    {
        return str_contains($this->value, '%');
    }
}
