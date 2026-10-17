<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Secrets\SecretRotator;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Rotate stored secrets via the active SecretVault driver.
 */
final class SecretsRotateCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.secrets:rotate {--otp= : A one-time code, when step-up asks for one}';

    protected $description = 'Re-wrap every stored secret under the active driver\'s new key material';

    public function handle(SecretRotator $rotator): int
    {
        $this->presentStepUpProof();

        try {
            $count = $rotator->rotateAll();
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        $this->components->info("Rotated {$count} stored secret(s).");

        return self::SUCCESS;
    }
}
