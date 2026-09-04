<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Exceptions\UnknownServer;

/**
 * Set the sticky default server for subsequent commands. The default is
 * persisted to a small state file so it survives across CLI invocations.
 */
final class ServerUseCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.server:use {name}';

    protected $description = 'Set the sticky default server for subsequent commands';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:server:use'];

    public function handle(ServerRegistry $registry): int
    {
        $name = $this->arg('name');

        if (! $registry->has($name)) {
            $this->failure(UnknownServer::named($name)->userMessage());

            return self::FAILURE;
        }

        $path = storage_path('db-console/state/default-server');
        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, $name);

        $this->components->info("Default server set to '{$name}'.");

        return self::SUCCESS;
    }
}
