<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Servers;

use Throwable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Simtabi\Laranail\DBConsole\Domain\Statement;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Exceptions\ExceptionTranslator;
use Simtabi\Laranail\DBConsole\Exceptions\ServerMisconfigured;

/**
 * Runs an engine's statements on the database each one is scoped to. An
 * unscoped statement, or one scoped to the database the admin connection is
 * already on, runs on the admin connection; anything else runs on a
 * short-lived connection to its database, opened with the admin
 * connection's own configuration (host, credentials and TLS options) and
 * closed again afterwards, so it never holds a session open on a database
 * that a later DROP DATABASE has to remove.
 */
final readonly class ScopedConnections
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * Run every statement in order, each on the database it is scoped to.
     *
     * @param array<string, mixed> $context sanitized context for error translation
     */
    public function run(AdminConnection $admin, StatementList $statements, array $context = []): void
    {
        $opened = [];

        try {
            foreach ($statements as $statement) {
                $this->connectionFor($admin, $statement, $opened)->runStatement($statement, $context);
            }
        } finally {
            $this->close($opened);
        }
    }

    /**
     * A single scalar from a read statement, on the database it is scoped to.
     *
     * @param array<string, mixed> $context
     */
    public function scalar(AdminConnection $admin, Statement $statement, array $context = []): mixed
    {
        $opened = [];

        try {
            return $this->connectionFor($admin, $statement, $opened)->scalarStatement($statement, $context);
        } finally {
            $this->close($opened);
        }
    }

    /**
     * @param array<string, AdminConnection> $opened connections opened so far, by name
     */
    private function connectionFor(AdminConnection $admin, Statement $statement, array &$opened): AdminConnection
    {
        if ($admin->runsHere($statement)) {
            return $admin;
        }

        /** @var string $database runsHere() is true for every unscoped statement */
        $database = $statement->database;
        $name = "db-console-scoped:{$admin->server}:{$database}";

        return $opened[$name] ??= $this->open($admin, $name, $database);
    }

    private function open(AdminConnection $admin, string $name, string $database): AdminConnection
    {
        $underlying = $admin->underlying();
        if (! $underlying instanceof Connection) {
            throw ServerMisconfigured::named(
                $admin->server,
                "cannot open a connection to database '{$database}': the admin connection does not expose its configuration",
            );
        }

        // The resolved config, with any `url` already expanded into fields.
        // Dropping the key keeps a url from overriding the database chosen here.
        $config = $underlying->getConfig();
        unset($config['url']);
        $config['database'] = $database;

        try {
            // connectUsing() rather than build(): build() also keeps the config,
            // password included, for the life of the process.
            return new AdminConnection($admin->server, $this->db->connectUsing($name, $config, true));
        } catch (Throwable $e) {
            throw ExceptionTranslator::from($e, ['server' => $admin->server, 'target' => $database]);
        }
    }

    /**
     * @param array<string, AdminConnection> $opened
     */
    private function close(array $opened): void
    {
        foreach (array_keys($opened) as $name) {
            $this->db->purge($name);
        }
    }
}
