<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Servers;

use Closure;
use Throwable;
use Simtabi\Laranail\DBConsole\Domain\Statement;
use Simtabi\Laranail\DBConsole\Domain\StatementList;

/**
 * The work inside ScopedConnections::transaction(): statements run on the
 * connection for the database each is scoped to, and every connection used
 * holds an open transaction until the session commits or rolls back.
 */
final class ScopedSession
{
    /** @var array<int, AdminConnection> connections with an open transaction, by object id */
    private array $open = [];

    /**
     * @param Closure(Statement): AdminConnection $connectionFor
     * @param array<string, mixed> $context sanitized context for error translation
     */
    public function __construct(
        private readonly Closure $connectionFor,
        private readonly array $context = [],
    ) {}

    public function run(StatementList $statements): void
    {
        foreach ($statements as $statement) {
            $this->begin($statement)->runStatement($statement, $this->context);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function select(Statement $statement): array
    {
        return $this->begin($statement)->selectStatement($statement, $this->context);
    }

    public function scalar(Statement $statement): mixed
    {
        return $this->begin($statement)->scalarStatement($statement, $this->context);
    }

    /**
     * Commit every open transaction, in the order the connections were first
     * used. Later work starts new transactions. The first failure stops the
     * rest, which are rolled back.
     */
    public function commit(): void
    {
        foreach ($this->open as $id => $connection) {
            // Drop it from the open set first: if the commit throws, its
            // transaction is gone either way and rollBack() must skip it.
            unset($this->open[$id]);
            $connection->commit($this->context);
        }
    }

    /**
     * Roll back every open transaction. A connection that fails to roll back
     * is already broken, and closing it discards the transaction anyway.
     */
    public function rollBack(): void
    {
        foreach ($this->open as $connection) {
            try {
                $connection->rollBack($this->context);
            } catch (Throwable) {
                // closed by ScopedConnections either way
            }
        }

        $this->open = [];
    }

    private function begin(Statement $statement): AdminConnection
    {
        $connection = ($this->connectionFor)($statement);
        $id = spl_object_id($connection);

        if (! isset($this->open[$id])) {
            $connection->beginTransaction($this->context);
            $this->open[$id] = $connection;
        }

        return $connection;
    }
}
