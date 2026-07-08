<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;

/**
 * Create a database.
 */
final class DbCreateCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.db:create {--server=} {--name=} {--charset=} {--collation=}';

    protected $description = 'Create a database on a server';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:db:create'];

    public function handle(DatabaseManager $databases): int
    {
        $name = $this->stringOption('name');
        $charset = $this->stringOption('charset') ?: (string) $this->config()->get('laranail.db-console.databases.default_charset', 'utf8mb4');
        $collation = $this->stringOption('collation') ?: (string) $this->config()->get('laranail.db-console.databases.default_collation', 'utf8mb4_unicode_ci');

        if ($name === '') {
            $this->failure('A database --name is required.');

            return self::FAILURE;
        }

        try {
            $result = $databases->create($this->server(), new DbName($name), new Charset($charset, $collation));
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        $this->success($result->alreadyExisted
            ? "Database '{$name}' already exists (adopted)."
            : "Database '{$name}' created.");

        return self::SUCCESS;
    }

    private function stringOption(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}
