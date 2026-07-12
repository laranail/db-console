<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Secrets\SecretRotator;

/**
 * Rotate stored secrets via the active SecretVault driver.
 */
final class SecretsRotateCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.secrets:rotate';

    protected $description = 'Re-wrap every stored secret under the active driver\'s new key material';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:secrets:rotate'];

    public function handle(SecretRotator $rotator): int
    {
        $count = $rotator->rotateAll();
        $this->components->info("Rotated {$count} stored secret(s).");

        return self::SUCCESS;
    }
}
