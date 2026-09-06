<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Drop a database. Requires typed confirmation (unless --force) and snapshots
 * a non-empty database first (backup-before-drop).
 */
final class DbDropCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.db:drop {--server=} {--name=} {--force}';

    protected $description = 'Drop a database (typed confirmation; backup-before-drop for non-empty databases)';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:db:drop'];

    public function handle(DatabaseManager $databases): int
    {
        $name = $this->option('name');
        $name = is_string($name) ? $name : '';
        if ($name === '') {
            $this->failure('A database --name is required.');

            return self::FAILURE;
        }

        if (! (bool) $this->option('force') && ! $this->confirmTyped($name)) {
            $this->failure('Confirmation did not match; aborted.');

            return self::FAILURE;
        }

        try {
            $databases->drop($this->server(), new DbName($name));
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        $this->success("Database '{$name}' dropped.");

        return self::SUCCESS;
    }

    private function confirmTyped(string $name): bool
    {
        if ($this->nonInteractive()) {
            // In CI, --force is required for a destructive op.
            return false;
        }

        return $this->ask("Type the database name '{$name}' to confirm the drop") === $name;
    }
}
