<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Enums\SecretDriver;
use Simtabi\Laranail\DBConsole\Secrets\SecretVaultManager;

/**
 * Show the active secret driver and warn if app_key is used outside local.
 */
final class SecretsDriverCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.secrets:driver';

    protected $description = 'Show the active secret driver and warn on insecure choices';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:secrets:driver'];

    public function handle(SecretVaultManager $manager): int
    {
        $driver = $manager->driverName();
        $this->components->info("Active secret driver: {$driver->value}");

        if ($driver === SecretDriver::AppKey && app()->environment('production')) {
            $this->components->warn('app_key stores the key next to the ciphertext — not recommended for production. Use kms, vault, or reference.');
        }

        return self::SUCCESS;
    }
}
