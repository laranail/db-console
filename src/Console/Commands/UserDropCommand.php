<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Drop a database account (typed confirmation unless --force).
 */
final class UserDropCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.user:drop {--server=} {--user=} {--host=} {--force}';

    protected $description = 'Drop a database account';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:user:drop'];

    public function handle(AccountManager $accounts): int
    {
        $user = $this->str('user');
        $host = $this->str('host') ?: 'localhost';
        if ($user === '') {
            $this->failure('A --user is required.');

            return self::FAILURE;
        }

        if (! (bool) $this->option('force') && ($this->nonInteractive() || $this->ask("Type the username '{$user}' to confirm the drop") !== $user)) {
            $this->failure('Confirmation did not match; aborted.');

            return self::FAILURE;
        }

        try {
            $accounts->drop($this->server(), new Username($user), new Host($host));
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        $this->success("Account '{$user}'@'{$host}' dropped.");

        return self::SUCCESS;
    }

    private function str(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}
