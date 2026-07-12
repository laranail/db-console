<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Services\AccountManager;

/**
 * Rotate an account password. --generate prints the new password once.
 */
final class UserPasswordCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.user:password {--server=} {--user=} {--host=} {--password=} {--generate}';

    protected $description = 'Rotate a database account password';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:user:password'];

    public function handle(AccountManager $accounts): int
    {
        $user = $this->str('user');
        $host = $this->str('host') ?: 'localhost';
        $passwordOption = $this->str('password');
        $generate = (bool) $this->option('generate');

        if ($user === '') {
            $this->failure('A --user is required.');

            return self::FAILURE;
        }

        try {
            $password = ($generate || $passwordOption === '') ? null : new Password($passwordOption);
            $result = $accounts->rotatePassword($this->server(), new Username($user), new Host($host), $password);
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        $this->success("Password rotated for '{$user}'@'{$host}'.");
        $generated = $result->takeGeneratedPassword();
        if ($generated !== null) {
            $this->components->warn('New password (shown once): ' . $generated);
        }

        return self::SUCCESS;
    }

    private function str(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}
