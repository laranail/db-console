<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourceKind;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Exceptions\ResourceAlreadyExists;
use Simtabi\Laranail\DBConsole\Console\Commands\Concerns\AsksAboutExistingResources;

/**
 * Create a database account. --generate prints a strong password once and
 * never logs it. An account that is already there is refused unless
 * --on-existing (or the answer to the prompt) says to adopt it.
 */
final class UserCreateCommand extends DBConsoleCommand
{
    use AsksAboutExistingResources;

    protected $signature = 'laranail::db-console.user:create {--server=} {--user=} {--host=} {--password=} {--generate}
        {--on-existing= : refuse, adopt or adopt_and_apply, when the account already exists}';

    protected $description = 'Create a database account';

    public function handle(AccountManager $accounts): int
    {
        $user = $this->str('user');
        $host = $this->str('host') ?: (string) $this->config()->get('laranail.db-console.accounts.default_host', 'localhost');
        $generate = (bool) $this->option('generate');
        $passwordOption = $this->str('password');

        if ($user === '') {
            $this->failure('A --user is required.');

            return self::FAILURE;
        }

        $onExisting = $this->onExistingOption();
        if ($onExisting === false) {
            return $this->invalidOnExisting();
        }

        try {
            $username = new Username($user);
            $hostValue = new Host($host);

            if (! $onExisting instanceof ExistingResourcePolicy && ! $this->nonInteractive() && $accounts->exists($this->server(), $username, $hostValue)) {
                $onExisting = $this->askOnExisting(ExistingResourceKind::Account, "Account '{$user}'@'{$host}'");
            }

            $password = ($generate || $passwordOption === '') ? null : new Password($passwordOption);
            $result = $accounts->create($this->server(), $username, $hostValue, $password, $onExisting);
        } catch (ResourceAlreadyExists $e) {
            return $this->refused($e);
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        if ($result->alreadyExisted) {
            $this->reportAdoption($result, "Account '{$user}'@'{$host}'");
        } else {
            $this->success("Account '{$user}'@'{$host}' created.");
        }

        $generated = $result->takeGeneratedPassword();
        if ($generated !== null) {
            $this->line('');
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
