<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Engines\Engine;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Servers\ScopedSession;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Servers\AdminConnection;
use Simtabi\Laranail\DBConsole\Events\DatabasesAttached;
use Simtabi\Laranail\DBConsole\Events\DatabasesDetached;
use Simtabi\Laranail\DBConsole\Events\PrivilegesGranted;
use Simtabi\Laranail\DBConsole\Events\PrivilegesRevoked;
use Simtabi\Laranail\DBConsole\Servers\ScopedConnections;
use Simtabi\Laranail\DBConsole\Exceptions\GrantNotApplied;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;
use Simtabi\Laranail\DBConsole\Services\Contracts\Catalog;
use Simtabi\Laranail\DBConsole\Services\Results\GrantEntry;
use Simtabi\Laranail\DBConsole\Engines\GrantVerifyingEngine;
use Simtabi\Laranail\DBConsole\Services\Results\BatchResult;
use Simtabi\Laranail\DBConsole\Engines\OwnerDelegatingEngine;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Engines\OwnershipReleasingEngine;
use Simtabi\Laranail\DBConsole\Services\Results\OperationResult;
use Simtabi\Laranail\DBConsole\Engines\DatabaseScopedGrantsEngine;
use Simtabi\Laranail\DBConsole\Events\OperationFailed as OperationFailedEvent;

/**
 * Grants and revokes privileges. The privilege set is already guaranteed
 * safe (PrivilegeSet's constructor rejected any forbidden/self-escalating
 * privilege), so this layer only orchestrates: authorize, resolve, ask the
 * engine to build the database-scoped GRANT/REVOKE, run, record, log, event.
 * Batch attach/detach (section 15) is added in A9.
 */
final readonly class PrivilegeManager
{
    public function __construct(
        private ServerRegistry $registry,
        private Authorizer $authorizer,
        private Dispatcher $events,
        private Catalog $catalog,
        private DBConsoleLogger $log,
        private ScopedConnections $scoped,
        private RoleMembership $membership,
    ) {}

    public function grant(string $server, Username $user, Host $host, DbName $db, PrivilegeSet $set): OperationResult
    {
        $this->authorizer->authorize(ConsolePermission::GrantCreate, "database:{$server}/{$db->value}");

        [$engine, $connection] = $this->registry->resolve($server);
        $target = $this->label($user, $host, $db);

        try {
            $context = ['operation' => OperationType::GrantCreate->value, 'target' => $target];

            if ($engine instanceof GrantVerifyingEngine && $engine instanceof OwnerDelegatingEngine && $engine instanceof OwnershipReleasingEngine) {
                $notCovered = $this->membership->actingFor(
                    $engine,
                    $connection,
                    $set,
                    $db,
                    $user,
                    $context,
                    function (array $notCovered) use ($engine, $connection, $set, $db, $user, $host, $context): array {
                        $this->grantVerified($engine, $connection, $set, $db, $user, $host, $context);

                        return $notCovered;
                    },
                );
            } elseif ($engine instanceof GrantVerifyingEngine) {
                $this->grantVerified($engine, $connection, $set, $db, $user, $host, $context);
            } else {
                $this->scoped->run($connection, $engine->grant($set, $db, $user, $host), $context);
            }
        } catch (DBConsoleException $e) {
            $this->fail(OperationType::GrantCreate, $server, $target, $e);
        }

        $this->catalog->recordGrant($server, $user, $host, $db, $set);
        $this->log->success(OperationType::GrantCreate->value, $server, [
            'target'     => $target,
            'preset'     => $set->preset->value,
            'privileges' => $set->values(),
        ]);
        $this->events->dispatch(new PrivilegesGranted($server, [
            'target' => $target,
            'preset' => $set->preset->value,
        ]));

        return OperationResult::succeeded(OperationType::GrantCreate, $server, array_filter([
            'username' => $user->value,
            'host'     => $host->value,
            'database' => $db->value,
            'preset'   => $set->preset->value,
            // Roles the admin could not act as: their objects, and what they
            // create later, are not covered by this grant.
            'not_covered' => implode(', ', $notCovered ?? []),
        ], static fn (string $value): bool => $value !== ''));
    }

    public function revoke(string $server, Username $user, Host $host, DbName $db, PrivilegeSet $set): OperationResult
    {
        $this->authorizer->authorize(ConsolePermission::GrantRevoke, "database:{$server}/{$db->value}");

        [$engine, $connection] = $this->registry->resolve($server);
        $target = $this->label($user, $host, $db);

        try {
            $context = ['operation' => OperationType::GrantRevoke->value, 'target' => $target];

            // Postgres revokes run inside the target database (see Statement),
            // all or nothing where DDL is transactional.
            $revoke = function () use ($engine, $connection, $set, $db, $user, $host, $context): void {
                $this->scoped->transaction($connection, function (ScopedSession $session) use ($engine, $set, $db, $user, $host): void {
                    $session->run($engine->revoke($set, $db, $user, $host));
                }, $context);
            };

            if ($engine instanceof GrantVerifyingEngine && $engine instanceof OwnerDelegatingEngine && $engine instanceof OwnershipReleasingEngine) {
                $this->membership->actingFor($engine, $connection, $set, $db, $user, $context, static function () use ($revoke): null {
                    $revoke();

                    return null;
                });
            } elseif ($engine instanceof GrantVerifyingEngine) {
                $revoke();
            } else {
                $this->scoped->run($connection, $engine->revoke($set, $db, $user, $host), $context);
            }
        } catch (DBConsoleException $e) {
            $this->fail(OperationType::GrantRevoke, $server, $target, $e);
        }

        $this->catalog->forgetGrant($server, $user, $host, $db);
        $this->log->success(OperationType::GrantRevoke->value, $server, ['target' => $target]);
        $this->events->dispatch(new PrivilegesRevoked($server, ['target' => $target]));

        return OperationResult::succeeded(OperationType::GrantRevoke, $server, [
            'username' => $user->value,
            'host'     => $host->value,
            'database' => $db->value,
        ]);
    }

    /**
     * Attach one user to many databases with one preset (section 15). Each
     * (user, database) pairing is its own audited grant; a pairing that fails
     * is reported without aborting the rest, and the result names exactly
     * which succeeded and which didn't.
     *
     * @param list<DbName> $databases
     */
    public function attach(string $server, Username $user, Host $host, array $databases, PrivilegeSet $set): BatchResult
    {
        $this->authorizer->authorize(ConsolePermission::Attach, "server:{$server}");

        $pairings = [];
        foreach ($databases as $db) {
            $pairings[] = $this->attemptPairing($server, $user, $host, $db, $set);
        }

        $result = new BatchResult($pairings);
        $this->events->dispatch(new DatabasesAttached($server, [
            'target'    => "{$user->value}@{$host->value}",
            'succeeded' => count($result->succeeded()),
            'failed'    => count($result->failed()),
        ]));

        return $result;
    }

    /**
     * Attach many users to one database with one preset (section 15).
     *
     * @param list<array{0: Username, 1: Host}> $users
     */
    public function attachMany(string $server, array $users, DbName $database, PrivilegeSet $set): BatchResult
    {
        $this->authorizer->authorize(ConsolePermission::Attach, "database:{$server}/{$database->value}");

        $pairings = [];
        foreach ($users as [$user, $host]) {
            $pairings[] = $this->attemptPairing($server, $user, $host, $database, $set);
        }

        $result = new BatchResult($pairings);
        $this->events->dispatch(new DatabasesAttached($server, [
            'target'    => $database->value,
            'succeeded' => count($result->succeeded()),
            'failed'    => count($result->failed()),
        ]));

        return $result;
    }

    /**
     * Detach one user from many databases: a batch of audited revokes. Never
     * drops the user or the databases — only the grants.
     *
     * @param list<DbName> $databases
     */
    public function detach(string $server, Username $user, Host $host, array $databases, PrivilegeSet $set): BatchResult
    {
        $this->authorizer->authorize(ConsolePermission::Detach, "server:{$server}");

        $pairings = [];
        foreach ($databases as $db) {
            $pairings[] = $this->attemptRevokePairing($server, $user, $host, $db, $set);
        }

        $result = new BatchResult($pairings);
        $this->events->dispatch(new DatabasesDetached($server, [
            'target'    => "{$user->value}@{$host->value}",
            'succeeded' => count($result->succeeded()),
            'failed'    => count($result->failed()),
        ]));

        return $result;
    }

    /**
     * Read an account's effective grants live from the server, one line each.
     *
     * @return list<string>
     */
    public function showGrants(string $server, Username $user, Host $host): array
    {
        return array_map(static fn (GrantEntry $entry): string => $entry->line(), $this->grants($server, $user, $host));
    }

    /**
     * Read an account's effective grants live from the server. On Postgres
     * each entry says where it comes from (the account itself, ownership,
     * PUBLIC or an inherited role); $directOnly keeps the account's own.
     *
     * @return list<GrantEntry>
     */
    public function grants(string $server, Username $user, Host $host, bool $directOnly = false): array
    {
        $this->authorizer->authorize(ConsolePermission::AccountView, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);
        $context = ['operation' => 'account.view'];

        $unreadable = [];
        if ($engine instanceof DatabaseScopedGrantsEngine) {
            [$databases, $unreadable] = $this->grantDatabases($engine, $connection, $user, $context);
            $statements = $engine->showGrantsIn($user, $databases);
        } else {
            $statements = $engine->showGrants($user, $host);
        }

        $entries = [];
        foreach ($this->scoped->select($connection, $statements, $context) as $row) {
            $entry = GrantEntry::fromRow($row);
            if (! $directOnly || $entry->direct()) {
                $entries[] = $entry;
            }
        }

        // A database the admin may not connect to still has grants in it;
        // saying so beats a list that looks complete.
        foreach ($unreadable as $name) {
            $entries[] = GrantEntry::unreadable($name);
        }

        return $entries;
    }

    /**
     * The databases to read an account's grants in, and those the admin may
     * not connect to.
     *
     * @param array<string, mixed> $context
     *
     * @return array{list<DbName>, list<string>}
     */
    private function grantDatabases(DatabaseScopedGrantsEngine $engine, AdminConnection $connection, Username $user, array $context): array
    {
        $readable = [];
        $unreadable = [];

        foreach ($engine->grantDatabases($user) as $statement) {
            foreach ($connection->selectStatement($statement, $context) as $row) {
                $name = (string) reset($row);
                if (in_array($row['can_connect'] ?? true, [false, 0, '0', 'f', 'false'], true)) {
                    $unreadable[] = $name;

                    continue;
                }

                $readable[] = new DbName($name);
            }
        }

        return [$readable, $unreadable];
    }

    /**
     * Grant a single pairing, capturing success/failure without aborting the
     * batch. A single grant is atomic at the statement level, so a failed
     * pairing leaves nothing partial to undo within itself.
     *
     * @return array{user: string, host: string, database: string, ok: bool, error: ?string}
     */
    private function attemptPairing(string $server, Username $user, Host $host, DbName $db, PrivilegeSet $set): array
    {
        try {
            $this->grant($server, $user, $host, $db, $set);

            return ['user' => $user->value, 'host' => $host->value, 'database' => $db->value, 'ok' => true, 'error' => null];
        } catch (DBConsoleException $e) {
            return ['user' => $user->value, 'host' => $host->value, 'database' => $db->value, 'ok' => false, 'error' => $e->userMessage()];
        }
    }

    /**
     * @return array{user: string, host: string, database: string, ok: bool, error: ?string}
     */
    private function attemptRevokePairing(string $server, Username $user, Host $host, DbName $db, PrivilegeSet $set): array
    {
        try {
            $this->revoke($server, $user, $host, $db, $set);

            return ['user' => $user->value, 'host' => $host->value, 'database' => $db->value, 'ok' => true, 'error' => null];
        } catch (DBConsoleException $e) {
            return ['user' => $user->value, 'host' => $host->value, 'database' => $db->value, 'ok' => false, 'error' => $e->userMessage()];
        }
    }

    /**
     * Grant and check the result in one transaction on the target database.
     * Postgres warns and grants what it can when the admin lacks a grant
     * option, so the GRANT succeeding proves nothing; the check reads the
     * account's effective privileges, and anything missing rolls the whole
     * grant back.
     *
     * @param array<string, mixed> $context
     */
    private function grantVerified(
        Engine&GrantVerifyingEngine $engine,
        AdminConnection $connection,
        PrivilegeSet $set,
        DbName $db,
        Username $user,
        Host $host,
        array $context,
    ): void {
        $this->scoped->transaction($connection, function (ScopedSession $session) use ($engine, $set, $db, $user, $host): void {
            $session->run($engine->grant($set, $db, $user, $host));

            $missing = [];
            foreach ($engine->missingGrants($set, $db, $user) as $statement) {
                foreach ($session->select($statement) as $row) {
                    $missing[] = ['object' => (string) $row['object'], 'privilege' => (string) $row['privilege']];
                }
            }

            if ($missing !== []) {
                throw GrantNotApplied::missing($db->value, $user->value, $missing);
            }
        }, $context);
    }

    private function label(Username $user, Host $host, DbName $db): string
    {
        return "{$user->value}@{$host->value} on {$db->value}";
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
