<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Services\AccountManager;

/**
 * List accounts on a server (live read).
 */
final class UserListCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.user:list {--server=}';

    protected $description = 'List database accounts on a server';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:user:list'];

    public function handle(AccountManager $accounts): int
    {
        $server = $this->server();
        foreach ($accounts->list($server) as $account) {
            $this->line("  {$account}");
        }
        $this->components->info("Accounts on '{$server}'.");

        return self::SUCCESS;
    }
}
