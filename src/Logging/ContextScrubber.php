<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Logging;

use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Domain\Password;

/**
 * A second line of defense over the self-redacting value objects: walks a
 * context array before it reaches the log channel and redacts anything that
 * is a secret by type OR by key name. Secrets are already impossible to log
 * (Password/Secret redact themselves), but a scrubber catches a raw string
 * that a caller mistakenly placed under a sensitive key.
 */
final class ContextScrubber
{
    /** Keys whose values are always redacted, regardless of type. */
    private const array SENSITIVE_KEYS = [
        'password', 'secret', 'credential', 'credentials', 'token',
        'api_key', 'apikey', 'private_key', 'passphrase', 'sql',
    ];

    private const string REDACTED = '[redacted]';

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public function scrub(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $clean[$key] = $this->scrubValue($key, $value);
        }

        return $clean;
    }

    private function scrubValue(int|string $key, mixed $value): mixed
    {
        if ($value instanceof Password || $value instanceof Secret) {
            return self::REDACTED;
        }

        if (is_string($key) && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            return $this->scrub($value);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return array_any(self::SENSITIVE_KEYS, fn (string $sensitive): bool => $normalized === $sensitive || str_contains($normalized, $sensitive));
    }
}
