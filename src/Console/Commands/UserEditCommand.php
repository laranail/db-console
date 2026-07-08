<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Services\AccountManager;

/**
 * Edit account config; --new-host runs the grant-preserving recreate.
 */
final class UserEditCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.user:edit {--server=} {--user=} {--host=} {--new-host=} {--rotate}';

    protected $description = 'Edit a database account (host change is a grant-preserving recreate)';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:user:edit'];

    public function handle(AccountManager $accounts): int
    {
        $user = $this->str('user');
        $host = $this->str('host');
        $newHost = $this->str('new-host');

        if ($user === '' || $host === '' || $newHost === '') {
            $this->failure('--user, --host and --new-host are required for a host change.');

            return self::FAILURE;
        }

        try {
            $result = $accounts->changeHost($this->server(), new Username($user), new Host($host), new Host($newHost), (bool) $this->option('rotate'));
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        $this->success("Changed host for '{$user}' from '{$host}' to '{$newHost}' (grants preserved).");
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
