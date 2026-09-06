<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use ValueError;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Services\ProvisioningWizard;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * The full create database + user + grant flow, with compensating rollback.
 */
final class WizardCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.wizard {--server=} {--db=} {--charset=} {--user=} {--host=} {--password=} {--preset=}';

    protected $description = 'Create a database, an account, and a grant in one guided, rollback-safe flow';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:wizard'];

    public function handle(ProvisioningWizard $wizard): int
    {
        $db = $this->str('db');
        $user = $this->str('user');
        $host = $this->str('host') ?: '%';
        $charset = $this->str('charset') ?: 'utf8mb4';
        $preset = $this->str('preset') ?: 'app_standard';
        $passwordOption = $this->str('password');

        if ($db === '' || $user === '') {
            $this->failure('--db and --user are required.');

            return self::FAILURE;
        }

        try {
            $password = $passwordOption === '' ? null : new Password($passwordOption);
            $result = $wizard->provision(
                $this->server(),
                new DbName($db),
                new Charset($charset, 'utf8mb4_unicode_ci'),
                new Username($user),
                new Host($host),
                PrivilegeSet::fromPreset(PrivilegePreset::from($preset)),
                $password,
            );
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        } catch (ValueError) {
            $this->failure("Unknown preset '{$preset}'.");

            return self::FAILURE;
        }

        $this->components->info('Done: database, account, and grant created.');
        $generated = $result->takeGeneratedPassword();
        if ($generated !== null) {
            $this->components->warn('Password (shown once — store it now): ' . $generated);
        }

        return self::SUCCESS;
    }

    private function str(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}
