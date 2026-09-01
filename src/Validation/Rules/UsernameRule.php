<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Validates an account name via the Username value object constructor.
 */
final class UsernameRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail((string) __('laranail-db-console::validation.string', ['attribute' => $attribute]));

            return;
        }

        try {
            new Username($value);
        } catch (DBConsoleException $e) {
            $fail($e->userMessage());
        }
    }
}
