<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Backup\BackupService;
use Simtabi\Laranail\DBConsole\Events\DatabaseAdopted;
use Simtabi\Laranail\DBConsole\Events\DatabaseCreated;
use Simtabi\Laranail\DBConsole\Events\DatabaseDropped;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Events\DatabaseBackedUp;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Servers\ScopedConnections;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourceKind;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;
use Simtabi\Laranail\DBConsole\Services\Contracts\Catalog;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Engines\EmptinessProbingEngine;
use Simtabi\Laranail\DBConsole\Services\Results\OperationResult;
use Simtabi\Laranail\DBConsole\Events\OperationFailed as OperationFailedEvent;

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
        private ScopedConnections $scoped,
        private ExistingResourceGuard $guard,
    ) {}

    /**
     * Create a database. One that is already there is handled by the
     * existing-resource policy ($onExisting, else the server's, else the
     * global `databases.on_existing`, default refuse): refused with
     * DatabaseAlreadyExists (HTTP 409), or adopted as it is. A database has
     * no password, so adopt_and_apply adopts.
     */
    public function create(string $server, DbName $db, Charset $charset, ?ExistingResourcePolicy $onExisting = null): OperationResult
    {
        $this->authorizer->authorize(ConsolePermission::DatabaseCreate, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);

        if ($this->exists($server, $db)) {
            return $this->adopt($server, $db, $onExisting);
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

        return OperationResult::succeeded(OperationType::DatabaseCreate, $server, ['database' => $db->value, 'adopted' => false]);
    }

    public function drop(string $server, DbName $db): OperationResult
    {
        $this->authorizer->authorize(ConsolePermission::DatabaseDrop, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);

        // Backup before drop: snapshot a non-empty database first so an
        // accidental drop is recoverable (section 7). Disabled/absent → a
        // logged notice, never a silent skip. The emptiness check reads the
        // server (inside the target, on Postgres), so its failure is a failed
        // drop and is audited as one.
        try {
            $empty = $this->isEmpty($server, $db);
        } catch (DBConsoleException $e) {
            $this->fail(OperationType::DatabaseDrop, $server, $db->value, $e);
        }

        if (! $empty) {
            // A failed snapshot stops the drop, and is audited like any other
            // failed drop.
            try {
                $path = $this->backups->snapshot($server, $db);
            } catch (DBConsoleException $e) {
                $this->fail(OperationType::DatabaseDrop, $server, $db->value, $e);
            }

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

        // Postgres keeps its catalog per database, so the count has to be read
        // inside the target database rather than from the admin connection.
        if ($engine instanceof EmptinessProbingEngine) {
            $count = 0;
            foreach ($engine->countUserObjects($db) as $statement) {
                $count += (int) $this->scoped->scalar($connection, $statement, ['operation' => 'database.view']);
            }

            return $count === 0;
        }

        // MySQL/MariaDB expose a reliable per-database table count from the
        // admin connection (information_schema.table_schema == the database).
        // For every other engine — where "empty" cannot be checked without
        // switching databases — report NON-empty conservatively, so backups
        // always run and a rollback never drops.
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

    /**
     * The database is already there: refuse it, or record it as managed as it
     * is. Its charset is not recorded, because db-console did not choose it.
     */
    private function adopt(string $server, DbName $db, ?ExistingResourcePolicy $onExisting): OperationResult
    {
        $this->guard->admit(ExistingResourceKind::Database, $server, $db->value, $onExisting);

        $this->catalog->recordDatabase($server, $db, '', null);
        $this->log->success(OperationType::DatabaseAdopted->value, $server, ['target' => $db->value]);
        $this->events->dispatch(new DatabaseAdopted($server, ['target' => $db->value]));

        return OperationResult::succeeded(
            OperationType::DatabaseCreate,
            $server,
            ['database' => $db->value, 'adopted' => true],
            alreadyExisted: true,
        );
    }

    private function fail(OperationType $operation, string $server, string $target, DBConsoleException $e): never
    {
        $this->log->failure($operation->value, $server, $e);
        $this->events->dispatch(new OperationFailedEvent($server, $operation, [
            'target' => $target,
            'code'   => $e->code()->value,
        ]));

        throw $e;
    }
}
