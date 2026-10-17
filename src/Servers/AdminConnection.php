<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Servers;

use Throwable;
use LogicException;
use Illuminate\Database\ConnectionInterface;
use Simtabi\Laranail\DBConsole\Domain\Statement;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Exceptions\ExceptionTranslator;

/**
 * The only thing in the package that touches a managed server. Wraps the
 * dedicated per-server admin PDO connection (never the app default) and
 * runs the statements an engine produced. Every low-level failure is
 * translated into a DBConsoleException before it can escape, so nothing raw
 * crosses the service boundary.
 *
 * DDL is not transactional in most engines, so this never wraps a
 * StatementList in a transaction — atomicity is the WizardExecutor's job
 * (compensating rollback).
 */
final readonly class AdminConnection
{
    public function __construct(
        public string $server,
        private ConnectionInterface $connection,
    ) {}

    /**
     * Run every statement in order against the server. Statements that carry
     * secret material (CREATE USER ... IDENTIFIED BY ...) execute their real
     * SQL; only the redacted form is ever surfaced elsewhere.
     *
     * @param array<string, mixed> $context sanitized context for error translation
     */
    public function run(StatementList $statements, array $context = []): void
    {
        foreach ($statements as $statement) {
            $this->runStatement($statement, $context);
        }
    }

    /**
     * Run one statement. A statement scoped to a database other than this
     * connection's is refused rather than run here, where it would act on
     * the wrong database and succeed: route those through ScopedConnections.
     *
     * @param array<string, mixed> $context sanitized context for error translation
     */
    public function runStatement(Statement $statement, array $context = []): void
    {
        $this->assertRunsHere($statement);

        try {
            $this->connection->statement($statement->sql);
        } catch (Throwable $e) {
            throw ExceptionTranslator::from($e, [...$context, 'server' => $this->server]);
        }
    }

    /**
     * A single scalar from a statement an engine produced, on this
     * connection. Same scope rule as runStatement().
     *
     * @param array<string, mixed> $context
     */
    public function scalarStatement(Statement $statement, array $context = []): mixed
    {
        $this->assertRunsHere($statement);

        return $this->scalar($statement->sql, [], $context);
    }

    /**
     * Rows from a read statement an engine produced, on this connection.
     * Same scope rule as runStatement().
     *
     * @param array<string, mixed> $context
     *
     * @return list<array<string, mixed>>
     */
    public function selectStatement(Statement $statement, array $context = []): array
    {
        $this->assertRunsHere($statement);

        return $this->select($statement->sql, $context);
    }

    /**
     * Open a transaction. Only meaningful on an engine with transactional
     * DDL (Postgres): MySQL commits implicitly on every DDL statement.
     *
     * @param array<string, mixed> $context
     */
    public function beginTransaction(array $context = []): void
    {
        $this->translated(fn () => $this->connection->beginTransaction(), $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function commit(array $context = []): void
    {
        $this->translated(fn () => $this->connection->commit(), $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function rollBack(array $context = []): void
    {
        $this->translated(fn () => $this->connection->rollBack(), $context);
    }

    /**
     * The database this connection is on.
     */
    public function database(): string
    {
        return (string) $this->connection->getDatabaseName();
    }

    /**
     * Whether a statement belongs on this connection: unscoped, or scoped
     * to the database this connection is already on.
     */
    public function runsHere(Statement $statement): bool
    {
        return $statement->database === null || $statement->database === $this->database();
    }

    /**
     * Run a read query and return rows. Reads are live — the server is the
     * source of truth, not the catalog.
     *
     * @param array<string, mixed> $context
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $context = []): array
    {
        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = array_map(
                static fn (object $row): array => (array) $row,
                $this->connection->select($sql),
            );

            return $rows;
        } catch (Throwable $e) {
            throw ExceptionTranslator::from($e, [...$context, 'server' => $this->server]);
        }
    }

    /**
     * A single scalar from a parameterized read (bindings are bound, never
     * interpolated) — used for metadata checks like "is this database empty".
     *
     * @param list<scalar> $bindings
     * @param array<string, mixed> $context
     */
    public function scalar(string $sql, array $bindings = [], array $context = []): mixed
    {
        try {
            return $this->connection->scalar($sql, $bindings);
        } catch (Throwable $e) {
            throw ExceptionTranslator::from($e, [...$context, 'server' => $this->server]);
        }
    }

    public function underlying(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function translated(callable $operation, array $context): void
    {
        try {
            $operation();
        } catch (Throwable $e) {
            throw ExceptionTranslator::from($e, [...$context, 'server' => $this->server]);
        }
    }

    private function assertRunsHere(Statement $statement): void
    {
        if (! $this->runsHere($statement)) {
            throw new LogicException(
                "statement is scoped to database '{$statement->database}' but the admin connection for server "
                . "'{$this->server}' is on '{$this->database()}'; run it through ScopedConnections",
            );
        }
    }
}
