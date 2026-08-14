<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an RBAC scope expression:
 *
 *   global
 *   server:<server-name>
 *   database:<server-name>/<database-pattern>
 *
 * The database part may carry a trailing * wildcard (shop_*), matching the
 * scope resolver's pattern support.
 */
final class ScopeRule implements ValidationRule
{
    private const string DATABASE_PATTERN = '/^[A-Za-z0-9_]{0,63}\*?$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail((string) __('laranail-db-console::validation.scope', ['attribute' => $attribute]));
        }
    }

    public static function isValid(string $value): bool
    {
        if ($value === 'global') {
            return true;
        }

        if (str_starts_with($value, 'server:')) {
            $server = substr($value, strlen('server:'));

            return preg_match(ServerNameRule::PATTERN, $server) === 1;
        }

        if (str_starts_with($value, 'database:')) {
            $target = substr($value, strlen('database:'));
            $parts = explode('/', $target);
            if (count($parts) !== 2) {
                return false;
            }

            [$server, $database] = $parts;

            return preg_match(ServerNameRule::PATTERN, $server) === 1
                && $database !== ''
                && $database !== '*'
                && preg_match(self::DATABASE_PATTERN, $database) === 1;
        }

        return false;
    }
}
