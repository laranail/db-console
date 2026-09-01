<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Services\AccountManager;

/**
 * Create a database account. --generate prints a strong password once and
 * never logs it.
 */
final class UserCreateCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.user:create {--server=} {--user=} {--host=} {--password=} {--generate}';

    protected $description = 'Create a database account';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:user:create'];

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

        try {
            $password = ($generate || $passwordOption === '') ? null : new Password($passwordOption);
            $result = $accounts->create($this->server(), new Username($user), new Host($host), $password);
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        if ($result->alreadyExisted) {
            $this->success("Account '{$user}'@'{$host}' already exists.");

            return self::SUCCESS;
        }

        $this->success("Account '{$user}'@'{$host}' created.");

        $generated = $result->takeGeneratedPassword();
        if ($generated !== null) {
            $this->line('');
            $this->components->warn('Password (shown once — store it now): '.$generated);
        }

        return self::SUCCESS;
    }

    private function str(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}
