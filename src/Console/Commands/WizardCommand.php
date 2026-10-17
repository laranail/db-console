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
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourceKind;
use Simtabi\Laranail\DBConsole\Services\ProvisioningWizard;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Exceptions\ResourceAlreadyExists;
use Simtabi\Laranail\DBConsole\Console\Commands\Concerns\AsksAboutExistingResources;

/**
 * The full create database + user + grant flow, with compensating rollback.
 * A database or account that is already there is refused unless
 * --on-existing (or the answer to the prompt) says to adopt it. Without
 * --password an adopted account keeps its own.
 */
final class WizardCommand extends DBConsoleCommand
{
    use AsksAboutExistingResources;

    protected $signature = 'laranail::db-console.wizard {--server=} {--db=} {--charset=} {--user=} {--host=} {--password=} {--preset=}
        {--on-existing= : refuse, adopt or adopt_and_apply, when the database or account already exists}';

    protected $description = 'Create a database, an account, and a grant in one guided, rollback-safe flow';

    public function handle(ProvisioningWizard $wizard, AccountManager $accounts, DatabaseManager $databases): int
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

        $onExisting = $this->onExistingOption();
        if ($onExisting === false) {
            return $this->invalidOnExisting();
        }

        try {
            if (! $onExisting instanceof ExistingResourcePolicy && ! $this->nonInteractive()) {
                if ($accounts->exists($this->server(), new Username($user), new Host($host))) {
                    $onExisting = $this->askOnExisting(ExistingResourceKind::Account, "Account '{$user}'@'{$host}'");
                } elseif ($databases->exists($this->server(), new DbName($db))) {
                    $onExisting = $this->askOnExisting(ExistingResourceKind::Database, "Database '{$db}'");
                }
            }

            $password = $passwordOption === '' ? null : new Password($passwordOption);
            $result = $wizard->provision(
                $this->server(),
                new DbName($db),
                new Charset($charset, 'utf8mb4_unicode_ci'),
                new Username($user),
                new Host($host),
                PrivilegeSet::fromPreset(PrivilegePreset::from($preset)),
                $password,
                $onExisting,
            );
        } catch (ResourceAlreadyExists $e) {
            return $this->refused($e);
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        } catch (ValueError) {
            $this->failure("Unknown preset '{$preset}'.");

            return self::FAILURE;
        }

        if ($result->alreadyExisted) {
            $this->reportAdoption($result, "Account '{$user}'@'{$host}'");
        }

        $this->components->info('Done: database, account, and grant in place.');
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
