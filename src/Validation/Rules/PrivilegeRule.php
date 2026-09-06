<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * Validates one privilege token through the PrivilegeSet guard: forbidden
 * (self-escalating/server-wide) tokens are rejected as forbidden, tokens
 * off the allow-list as unknown — identical to what the domain layer would
 * throw.
 */
final class PrivilegeRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail((string) __('laranail-db-console::validation.string', ['attribute' => $attribute]));

            return;
        }

        try {
            PrivilegeSet::custom([$value]);
        } catch (DBConsoleException $e) {
            $fail($e->userMessage());
        }
    }
}
