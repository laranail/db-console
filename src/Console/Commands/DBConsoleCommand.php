<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;

/**
 * Base for every DBConsole artisan command. Extends the laranail/console
 * enhanced command (rich output + services) and enables the
 * laranail::db-console.<command> namespaced name via SupportsNamespacedNames,
 * with a short db-console:<command> alias declared per command.
 *
 * Every command takes --server (default when omitted) and refuses, with a
 * clear message, operations the active engine doesn't support.
 */
abstract class DBConsoleCommand extends Command
{
    use SupportsNamespacedNames;

    /**
     * The server named by --server, or the configured default.
     */
    protected function server(): string
    {
        $server = $this->option('server');
        if (is_string($server) && $server !== '') {
            return $server;
        }

        return app(ServerRegistry::class)->default();
    }

    protected function config(): Config
    {
        return app(Config::class);
    }

    /**
     * A string option value (empty string when unset or non-scalar).
     */
    protected function opt(string $key): string
    {
        $value = $this->option($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A string argument value.
     */
    protected function arg(string $key): string
    {
        $value = $this->argument($key);

        return is_scalar($value) ? (string) $value : '';
    }

    protected function success(string $message): void
    {
        $this->components->info($message);
    }

    protected function failure(string $message): void
    {
        $this->components->error($message);
    }

    /**
     * Whether the command is running non-interactively (--no-interaction or
     * CI), so it uses flags instead of prompts.
     */
    protected function nonInteractive(): bool
    {
        return (bool) $this->option('no-interaction');
    }
}
