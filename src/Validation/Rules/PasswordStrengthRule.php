<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Validates password strength via the Password value object constructor,
 * applying the configured policy minimum
 * (laranail.db-console.accounts.password_min_length) on top of the
 * constructor's hard floor.
 */
final class PasswordStrengthRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail((string) __('laranail-db-console::validation.string', ['attribute' => $attribute]));

            return;
        }

        $minLength = (int) config(
            'laranail.db-console.accounts.password_min_length',
            Password::DEFAULT_MIN_LENGTH,
        );

        try {
            new Password($value, max($minLength, Password::DEFAULT_MIN_LENGTH));
        } catch (DBConsoleException $e) {
            $fail($e->userMessage());
        }
    }
}
