<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a registry server name (config key or catalog-registered
 * name): letters, digits, underscore, hyphen.
 */
final class ServerNameRule implements ValidationRule
{
    public const string PATTERN = '/^[A-Za-z0-9_\-]{1,64}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail((string) __('db-console::validation.server_name', ['attribute' => $attribute]));
        }
    }
}
