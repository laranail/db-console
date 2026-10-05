<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Authorization;

/**
 * Announces a deprecated gate ability once per process, naming its vendor-scoped replacement.
 *
 * The bare `db-console.*` abilities are checked from host policies, Blade `@can` directives and
 * middleware on every request; a notice per check would flood the log, so each name is announced
 * the first time it is checked and never again.
 */
final class DeprecatedAbilities
{
    /** @var array<string, true> */
    private static array $announced = [];

    public static function announce(string $ability, string $replacement): void
    {
        if (isset(self::$announced[$ability])) {
            return;
        }

        self::$announced[$ability] = true;

        trigger_error(sprintf(
            'laranail/db-console: the gate ability [%s] is deprecated and will be removed no earlier than the next minor after 0.1. Use [%s] instead.',
            $ability,
            $replacement,
        ), E_USER_DEPRECATED);
    }

    /**
     * Forget which abilities were announced. For test suites; a process announces each name once
     * by design.
     */
    public static function forgetWarnings(): void
    {
        self::$announced = [];
    }
}
