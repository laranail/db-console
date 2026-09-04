<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Servers;

use Throwable;
use Illuminate\Database\ConnectionInterface;
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
            try {
                $this->connection->statement($statement->sql);
            } catch (Throwable $e) {
                throw ExceptionTranslator::from($e, [...$context, 'server' => $this->server]);
            }
        }
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
}
