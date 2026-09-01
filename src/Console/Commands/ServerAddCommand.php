<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Models\DbServer;

/**
 * Register a catalog-backed server (added at runtime, editable).
 */
final class ServerAddCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.server:add {name} {--engine=mysql} {--connection=db_console_admin} {--host=} {--port=}';

    protected $description = 'Register a catalog-backed server';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:server:add'];

    public function handle(): int
    {
        $name = $this->arg('name');
        $engine = EngineType::tryFrom($this->opt('engine'));
        if ($engine === null) {
            $this->failure('Unknown engine; expected one of: '.implode(', ', EngineType::values()));

            return self::FAILURE;
        }

        DbServer::query()->updateOrCreate(['name' => $name], [
            'engine' => $engine,
            'connection_ref' => $this->opt('connection'),
            'host' => is_string($this->option('host')) ? $this->option('host') : null,
            'port' => is_numeric($this->option('port')) ? (int) $this->option('port') : null,
            'is_managed' => true,
        ]);

        $this->components->info("Server '{$name}' registered ({$engine->value}). Run doctor to health-check it.");

        return self::SUCCESS;
    }
}
