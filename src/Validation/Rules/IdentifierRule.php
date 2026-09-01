<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Validates a database name by constructing the DbName value object — the
 * rule and the constructor cannot drift because the rule IS the
 * constructor. Shared verbatim by the API (FormRequests), the CLI
 * (Prompter via LaravelRule), and the web UI (RuleProvider).
 */
final class IdentifierRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail((string) __('laranail-db-console::validation.string', ['attribute' => $attribute]));

            return;
        }

        try {
            new DbName($value);
        } catch (DBConsoleException $e) {
            $fail($e->userMessage());
        }
    }
}
