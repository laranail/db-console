<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Events\DatabasesAttached;
use Simtabi\Laranail\DBConsole\Events\DatabasesDetached;
use Simtabi\Laranail\DBConsole\Events\OperationFailed as OperationFailedEvent;
use Simtabi\Laranail\DBConsole\Events\PrivilegesGranted;
use Simtabi\Laranail\DBConsole\Events\PrivilegesRevoked;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;
use Simtabi\Laranail\DBConsole\Services\Contracts\Catalog;
use Simtabi\Laranail\DBConsole\Services\Results\BatchResult;
use Simtabi\Laranail\DBConsole\Services\Results\OperationResult;

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
    ) {}

    public function grant(string $server, Username $user, Host $host, DbName $db, PrivilegeSet $set): OperationResult
    {
        $this->authorizer->authorize(ConsolePermission::GrantCreate, "database:{$server}/{$db->value}");

        [$engine, $connection] = $this->registry->resolve($server);
        $target = $this->label($user, $host, $db);

        try {
            $connection->run(
                $engine->grant($set, $db, $user, $host),
                ['operation' => OperationType::GrantCreate->value, 'target' => $target],
            );
        } catch (DBConsoleException $e) {
            $this->fail(OperationType::GrantCreate, $server, $target, $e);
        }

        $this->catalog->recordGrant($server, $user, $host, $db, $set);
        $this->log->success(OperationType::GrantCreate->value, $server, [
            'target' => $target,
            'preset' => $set->preset->value,
            'privileges' => $set->values(),
        ]);
        $this->events->dispatch(new PrivilegesGranted($server, [
            'target' => $target,
            'preset' => $set->preset->value,
        ]));

        return OperationResult::succeeded(OperationType::GrantCreate, $server, [
            'username' => $user->value,
            'host' => $host->value,
            'database' => $db->value,
            'preset' => $set->preset->value,
        ]);
    }

    public function revoke(string $server, Username $user, Host $host, DbName $db, PrivilegeSet $set): OperationResult
    {
        $this->authorizer->authorize(ConsolePermission::GrantRevoke, "database:{$server}/{$db->value}");

        [$engine, $connection] = $this->registry->resolve($server);
        $target = $this->label($user, $host, $db);

        try {
            $connection->run(
                $engine->revoke($set, $db, $user, $host),
                ['operation' => OperationType::GrantRevoke->value, 'target' => $target],
            );
        } catch (DBConsoleException $e) {
            $this->fail(OperationType::GrantRevoke, $server, $target, $e);
        }

        $this->catalog->forgetGrant($server, $user, $host, $db);
        $this->log->success(OperationType::GrantRevoke->value, $server, ['target' => $target]);
        $this->events->dispatch(new PrivilegesRevoked($server, ['target' => $target]));

        return OperationResult::succeeded(OperationType::GrantRevoke, $server, [
            'username' => $user->value,
            'host' => $host->value,
            'database' => $db->value,
        ]);
    }

    /**
     * Attach one user to many databases with one preset (section 15). Each
     * (user, database) pairing is its own audited grant; a pairing that fails
     * is reported without aborting the rest, and the result names exactly
     * which succeeded and which didn't.
     *
     * @param  list<DbName>  $databases
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
            'target' => "{$user->value}@{$host->value}",
            'succeeded' => count($result->succeeded()),
            'failed' => count($result->failed()),
        ]));

        return $result;
    }

    /**
     * Attach many users to one database with one preset (section 15).
     *
     * @param  list<array{0: Username, 1: Host}>  $users
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
            'target' => $database->value,
            'succeeded' => count($result->succeeded()),
            'failed' => count($result->failed()),
        ]));

        return $result;
    }

    /**
     * Detach one user from many databases: a batch of audited revokes. Never
     * drops the user or the databases — only the grants.
     *
     * @param  list<DbName>  $databases
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
            'target' => "{$user->value}@{$host->value}",
            'succeeded' => count($result->succeeded()),
            'failed' => count($result->failed()),
        ]));

        return $result;
    }

    /**
     * Read an account's effective grants live from the server.
     *
     * @return list<string>
     */
    public function showGrants(string $server, Username $user, Host $host): array
    {
        $this->authorizer->authorize(ConsolePermission::AccountView, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);

        $grants = [];
        foreach ($engine->showGrants($user, $host) as $statement) {
            foreach ($connection->select($statement->sql, ['operation' => 'account.view']) as $row) {
                $grants[] = implode(' ', array_map(strval(...), array_values($row)));
            }
        }

        return $grants;
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

    private function label(Username $user, Host $host, DbName $db): string
    {
        return "{$user->value}@{$host->value} on {$db->value}";
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
