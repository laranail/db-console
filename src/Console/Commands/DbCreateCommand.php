<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourceKind;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Exceptions\ResourceAlreadyExists;
use Simtabi\Laranail\DBConsole\Console\Commands\Concerns\AsksAboutExistingResources;

/**
 * Create a database. One that is already there is refused unless
 * --on-existing (or the answer to the prompt) says to adopt it.
 */
final class DbCreateCommand extends DBConsoleCommand
{
    use AsksAboutExistingResources;

    protected $signature = 'laranail::db-console.db:create {--server=} {--name=} {--charset=} {--collation=}
        {--on-existing= : refuse or adopt, when the database already exists}';

    protected $description = 'Create a database on a server';

    public function handle(DatabaseManager $databases): int
    {
        $name = $this->stringOption('name');
        $charset = $this->stringOption('charset') ?: (string) $this->config()->get('laranail.db-console.databases.default_charset', 'utf8mb4');
        $collation = $this->stringOption('collation') ?: (string) $this->config()->get('laranail.db-console.databases.default_collation', 'utf8mb4_unicode_ci');

        if ($name === '') {
            $this->failure('A database --name is required.');

            return self::FAILURE;
        }

        $onExisting = $this->onExistingOption();
        if ($onExisting === false) {
            return $this->invalidOnExisting();
        }

        try {
            $db = new DbName($name);

            if (! $onExisting instanceof ExistingResourcePolicy && ! $this->nonInteractive() && $databases->exists($this->server(), $db)) {
                $onExisting = $this->askOnExisting(ExistingResourceKind::Database, "Database '{$name}'");
            }

            $result = $databases->create($this->server(), $db, new Charset($charset, $collation), $onExisting);
        } catch (ResourceAlreadyExists $e) {
            return $this->refused($e);
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        if ($result->alreadyExisted) {
            $this->reportAdoption($result, "Database '{$name}'");
        } else {
            $this->success("Database '{$name}' created.");
        }

        return self::SUCCESS;
    }
}
