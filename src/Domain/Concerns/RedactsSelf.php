<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain\Concerns;

/**
 * Makes a sensitive value object redact itself on every accidental exposure
 * path: string interpolation, var_dump/dd (__debugInfo), json_encode
 * (jsonSerialize), and serialization. The only way to read the real value
 * is the explicit reveal() method.
 */
trait RedactsSelf
{
    public function __toString(): string
    {
        return self::REDACTED;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /**
     * Secrets deliberately do not survive serialization (queues, sessions,
     * caches). The redacted placeholder is stored instead of the value.
     *
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        return ['value' => self::REDACTED];
    }

    /**
     * @param  array<string, string>  $data
     */
    public function __unserialize(array $data): void
    {
        $this->value = self::REDACTED;
    }
}
