<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Servers\ScopedSession;
use Simtabi\Laranail\DBConsole\Support\DriverBoolean;
use Simtabi\Laranail\DBConsole\Servers\AdminConnection;
use Simtabi\Laranail\DBConsole\Exceptions\RollbackFailed;
use Simtabi\Laranail\DBConsole\Servers\ScopedConnections;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Engines\OwnershipReleasingEngine;
use Simtabi\Laranail\DBConsole\Exceptions\InsufficientPrivilege;

/**
 * Drops an account that cannot be dropped while it owns anything (Postgres),
 * leaving as little behind as Postgres allows when a step fails:
 *
 * 1. Read the databases the account has objects or privileges in, and refuse
 *    before changing anything if the admin cannot connect to one of them.
 * 2. Take membership in the account, unless the admin already has its
 *    privileges (a superuser, or an earlier grant). Releasing ownership needs it.
 * 3. Release ownership inside every dependent database in one transaction per
 *    database, and commit them together; any failure rolls all of them back.
 * 4. Release on the admin's own database and drop the account, in one
 *    transaction. This cannot share the transactions in step 3: DROP ROLE
 *    sees the dependencies another session has not committed yet.
 * 5. If step 3 or 4 fails, give back the membership step 2 took. If that
 *    fails too, escalate as RollbackFailed: the admin is left a member.
 *
 * Only a failure in step 4 after step 3 committed leaves a partial result:
 * the account's objects in other databases then belong to the admin.
 */
final readonly class OwnershipReleasingDrop
{
    public function __construct(private ScopedConnections $scoped) {}

    /**
     * @param array<string, mixed> $context sanitized context for error translation
     */
    public function drop(OwnershipReleasingEngine $engine, AdminConnection $admin, Username $user, Host $host, array $context = []): void
    {
        $databases = $this->dependentDatabases($engine, $admin, $user, $context);
        $alreadyHeld = $this->holdsPrivileges($engine, $admin, $user, $context);

        if (! $alreadyHeld) {
            $this->scoped->run($admin, $engine->takeMembership($user), $context);
        }

        try {
            $this->scoped->transaction($admin, function (ScopedSession $session) use ($engine, $user, $host, $databases): void {
                $session->run($engine->releaseOwnershipIn($user, $databases));
                $session->commit();
                $session->run($engine->dropReleasedAccount($user, $host));
            }, $context);
        } catch (DBConsoleException $e) {
            if (! $alreadyHeld) {
                $this->giveBackMembership($engine, $admin, $user, $context, $e);
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<DbName>
     */
    private function dependentDatabases(OwnershipReleasingEngine $engine, AdminConnection $admin, Username $user, array $context): array
    {
        $databases = [];
        $unreachable = [];

        foreach ($engine->dependentDatabases($user) as $statement) {
            foreach ($admin->selectStatement($statement, $context) as $row) {
                $name = (string) reset($row);
                if (! DriverBoolean::truthy($row['can_connect'] ?? true)) {
                    $unreachable[] = $name;

                    continue;
                }

                $databases[] = new DbName($name);
            }
        }

        if ($unreachable !== []) {
            throw InsufficientPrivilege::forOperation(
                "dropping '{$user->value}': CONNECT on database '" . implode("', '", $unreachable) . "'",
            );
        }

        return $databases;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function holdsPrivileges(OwnershipReleasingEngine $engine, AdminConnection $admin, Username $user, array $context): bool
    {
        foreach ($engine->holdsPrivilegesOf($user) as $statement) {
            if (! DriverBoolean::truthy($admin->scalarStatement($statement, $context))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function giveBackMembership(
        OwnershipReleasingEngine $engine,
        AdminConnection $admin,
        Username $user,
        array $context,
        DBConsoleException $cause,
    ): void {
        try {
            $this->scoped->run($admin, $engine->releaseMembership($user), $context);
        } catch (DBConsoleException $e) {
            throw RollbackFailed::whileCompensating(
                "give back membership in '{$user->value}'",
                [...$context, 'cause' => $cause->code()->value],
                $e,
            );
        }
    }
}
