<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\DBConsole\Backup\BackupService;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Events\DatabaseBackedUp;
use Simtabi\Laranail\DBConsole\Events\DatabaseCreated;
use Simtabi\Laranail\DBConsole\Events\DatabaseDropped;
use Simtabi\Laranail\DBConsole\Events\OperationFailed as OperationFailedEvent;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;
use Simtabi\Laranail\DBConsole\Services\Contracts\Catalog;
use Simtabi\Laranail\DBConsole\Services\Results\OperationResult;

/**
 * Creates, lists, and drops databases on a registered server. Like every
 * service: authorize → resolve the server's (engine, connection) → check
 * the capability → re-check preconditions live → ask the engine to build
 * statements → run them → record + log + dispatch. It never builds SQL
 * itself and never touches a connection other than the resolved admin one.
 */
final readonly class DatabaseManager
{
    public function __construct(
        private ServerRegistry $registry,
        private Authorizer $authorizer,
        private Dispatcher $events,
        private Catalog $catalog,
        private DBConsoleLogger $log,
        private BackupService $backups,
    ) {}

    public function create(string $server, DbName $db, Charset $charset): OperationResult
    {
        $this->authorizer->authorize(ConsolePermission::DatabaseCreate, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);

        // Idempotency: an already-existing database is a handled outcome, not
        // a raw driver error.
        if ($this->exists($server, $db)) {
            return OperationResult::succeeded(
                OperationType::DatabaseCreate,
                $server,
                ['database' => $db->value],
                alreadyExisted: true,
            );
        }

        try {
            $connection->run(
                $engine->createDatabase($db, $charset),
                ['operation' => OperationType::DatabaseCreate->value, 'target' => $db->value],
            );
        } catch (DBConsoleException $e) {
            $this->fail(OperationType::DatabaseCreate, $server, $db->value, $e);
        }

        $this->catalog->recordDatabase($server, $db, $charset->value, $charset->collation);
        $this->log->success(OperationType::DatabaseCreate->value, $server, ['target' => $db->value]);
        $this->events->dispatch(new DatabaseCreated($server, ['target' => $db->value]));

        return OperationResult::succeeded(OperationType::DatabaseCreate, $server, ['database' => $db->value]);
    }

    public function drop(string $server, DbName $db): OperationResult
    {
        $this->authorizer->authorize(ConsolePermission::DatabaseDrop, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);

        // Backup before drop: snapshot a non-empty database first so an
        // accidental drop is recoverable (section 7). Disabled/absent → a
        // logged notice, never a silent skip.
        if (! $this->isEmpty($server, $db)) {
            $path = $this->backups->snapshot($server, $db);
            if ($path !== null) {
                $this->events->dispatch(new DatabaseBackedUp($server, ['target' => $db->value, 'path' => $path]));
            }
        }

        try {
            $connection->run(
                $engine->dropDatabase($db),
                ['operation' => OperationType::DatabaseDrop->value, 'target' => $db->value],
            );
        } catch (DBConsoleException $e) {
            $this->fail(OperationType::DatabaseDrop, $server, $db->value, $e);
        }

        $this->catalog->forgetDatabase($server, $db);
        $this->log->success(OperationType::DatabaseDrop->value, $server, ['target' => $db->value]);
        $this->events->dispatch(new DatabaseDropped($server, ['target' => $db->value]));

        return OperationResult::succeeded(OperationType::DatabaseDrop, $server, ['database' => $db->value]);
    }

    /**
     * List databases live from the server (the catalog is not the source of
     * truth). Objects created outside DBConsole appear here too.
     *
     * @return list<string>
     */
    public function list(string $server): array
    {
        $this->authorizer->authorize(ConsolePermission::DatabaseView, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);

        $names = [];
        foreach ($engine->listDatabases() as $statement) {
            foreach ($connection->select($statement->sql, ['operation' => 'database.view']) as $row) {
                $names[] = (string) reset($row);
            }
        }

        return $names;
    }

    public function exists(string $server, DbName $db): bool
    {
        return in_array($db->value, $this->list($server), true);
    }

    /**
     * Whether a database has no user tables — the safety condition for a
     * compensating rollback drop. Reads live via a parameterized metadata
     * query (the name is bound, never interpolated).
     */
    public function isEmpty(string $server, DbName $db): bool
    {
        [$engine, $connection] = $this->registry->resolve($server);

        // Only MySQL/MariaDB expose a reliable per-database table count from
        // the admin connection (information_schema.table_schema == the
        // database). For every other engine — where "empty" cannot be
        // checked without switching databases — report NON-empty
        // conservatively, so backups always run and a rollback never drops.
        if (! $engine->type()->isMysqlFamily()) {
            return false;
        }

        $count = $connection->scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?',
            [$db->value],
            ['operation' => 'database.view'],
        );

        return (int) $count === 0;
    }

    /**
     * Compensating rollback for a database this run created: drop it ONLY if
     * it is still empty (nothing was created inside it since). No gate — this
     * is a system rollback undoing the wizard's own forward step, not a
     * user-initiated drop. Used exclusively by WizardExecutor.
     *
     * @internal
     */
    public function rollbackCreatedDatabase(string $server, DbName $db): void
    {
        if (! $this->exists($server, $db) || ! $this->isEmpty($server, $db)) {
            return;   // never destroy pre-existing or non-empty data
        }

        [$engine, $connection] = $this->registry->resolve($server);
        $connection->run(
            $engine->dropDatabase($db),
            ['operation' => OperationType::DatabaseDrop->value, 'target' => $db->value, 'rollback' => true],
        );
        $this->catalog->forgetDatabase($server, $db);
    }

    private function fail(OperationType $operation, string $server, string $target, DBConsoleException $e): never
    {
        $this->log->failure($operation->value, $server, $e);
        $this->events->dispatch(new OperationFailedEvent($server, $operation, [
            'target' => $target,
            'code' => $e->code()->value,
        ]));

        throw $e;
    }
}
