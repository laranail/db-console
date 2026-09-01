<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Services\DatabaseManager;

/**
 * List databases on a server (live read).
 */
final class DbListCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.db:list {--server=}';

    protected $description = 'List databases on a server (live from the server, not the catalog)';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:db:list'];

    public function handle(DatabaseManager $databases): int
    {
        $server = $this->server();
        $names = $databases->list($server);

        if ($names === []) {
            $this->components->info("No databases on '{$server}'.");

            return self::SUCCESS;
        }

        foreach ($names as $name) {
            $this->line("  {$name}");
        }

        $this->components->info(count($names)." database(s) on '{$server}'.");

        return self::SUCCESS;
    }
}
