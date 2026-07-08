<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Validates a host scope via the Host value object constructor.
 */
final class HostRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail((string) __('db-console::validation.string', ['attribute' => $attribute]));

            return;
        }

        try {
            new Host($value);
        } catch (DBConsoleException $e) {
            $fail($e->userMessage());
        }
    }
}
