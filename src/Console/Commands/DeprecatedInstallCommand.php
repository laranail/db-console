<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Illuminate\Console\Command;

/**
 * The bare `db-console:install` name the install command carried until 0.1.
 *
 * The install command itself is laranail/package-tools' DefinedInstallCommand, which is final and
 * takes no aliases, so the old name is kept as this hidden forwarder: it prints one line naming the
 * replacement, then runs `laranail::db-console.install` and returns its exit code.
 *
 * @deprecated Use `laranail::db-console.install`. Removed no earlier than the next minor after 0.1.
 */
final class DeprecatedInstallCommand extends Command
{
    public const string REPLACEMENT = 'laranail::db-console.install';

    protected $signature = 'db-console:install';

    protected $description = 'Deprecated alias of laranail::db-console.install';

    protected $hidden = true;

    public function handle(): int
    {
        $this->warn(sprintf(
            'Deprecated: [%s] is a deprecated alias and will be removed no earlier than the next minor after 0.1. Use [%s] instead.',
            $this->getName(),
            self::REPLACEMENT,
        ));

        return $this->call(self::REPLACEMENT);
    }
}
