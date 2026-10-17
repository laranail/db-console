<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api;

/**
 * Announces a deprecated config key once per process, naming the key that
 * replaced it. The API reads its settings on every request, so a notice per
 * read would flood the log.
 */
final class DeprecatedConfigKeys
{
    /** @var array<string, true> */
    private static array $announced = [];

    public static function announce(string $key, string $replacement): void
    {
        if (isset(self::$announced[$key])) {
            return;
        }

        self::$announced[$key] = true;

        trigger_error(sprintf(
            'laranail/db-console: the config key [laranail.db-console.%s] is deprecated and will be removed no earlier than the next minor after 0.1. Set [laranail.db-console.%s] instead.',
            $key,
            $replacement,
        ), E_USER_DEPRECATED);
    }

    /**
     * Forget which keys were announced. For test suites.
     */
    public static function forgetWarnings(): void
    {
        self::$announced = [];
    }
}
