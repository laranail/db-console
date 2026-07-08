<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;

/**
 * List registered servers and their engines.
 */
final class ServerListCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.server:list';

    protected $description = 'List registered servers and their engines';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:server:list'];

    public function handle(ServerRegistry $registry): int
    {
        $default = $registry->default();
        foreach ($registry->names() as $name) {
            $definition = $registry->definition($name);
            $marker = $name === $default ? '*' : ' ';
            $this->line("{$marker} {$name}  ({$definition->engine->value}, connection: {$definition->connection})");
        }

        return self::SUCCESS;
    }
}
