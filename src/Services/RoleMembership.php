<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Closure;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Servers\AdminConnection;
use Simtabi\Laranail\DBConsole\Exceptions\RollbackFailed;
use Simtabi\Laranail\DBConsole\Servers\ScopedConnections;
use Simtabi\Laranail\DBConsole\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\DBConsole\Engines\OwnerDelegatingEngine;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Engines\OwnershipReleasingEngine;

/**
 * Runs a grant or revoke with the admin holding the privileges of every role
 * it acts as, and gives back what it took. In Postgres only an object's owner,
 * or a role with the owner's privileges, may grant on it, and a default
 * privilege can only be set FOR a role whose privileges the admin has.
 *
 * Membership is taken (and committed) before the work starts, because the
 * work runs on another session that has to see it, and given back afterwards
 * whether the work succeeded or not. A role the admin already has the
 * privileges of is left alone. One it cannot take (no ADMIN OPTION, or a name
 * db-console cannot quote) is passed to the work as not covered, so the
 * caller can report it rather than fail.
 */
final readonly class RoleMembership
{
    public function __construct(
        private ScopedConnections $scoped,
        private DBConsoleLogger $log,
    ) {}

    /**
     * @template T
     *
     * @param Closure(list<string> $notCovered): T $work
     * @param array<string, mixed> $context sanitized context for error translation
     *
     * @return T
     */
    public function actingFor(
        OwnerDelegatingEngine&OwnershipReleasingEngine $engine,
        AdminConnection $admin,
        PrivilegeSet $set,
        DbName $db,
        Username $user,
        array $context,
        Closure $work,
    ): mixed {
        [$toTake, $notCovered] = $this->rolesToAssume($engine, $admin, $set, $db, $user, $context);

        $result = $this->actingAs($engine, $admin, $toTake, $context, static fn (): mixed => $work($notCovered));

        if ($notCovered !== []) {
            $this->log->write(Severity::Warning, (string) ($context['operation'] ?? 'grant'), [
                ...$context,
                'not_covered' => $notCovered,
                'reason'      => 'the admin cannot act as these roles, so their objects and future objects are not covered',
            ]);
        }

        return $result;
    }

    /**
     * Run $work holding the privileges of each of $roles, then give back the
     * memberships taken, whether $work succeeded or not.
     *
     * @template T
     *
     * @param list<Username> $roles roles the admin does not yet have the privileges of
     * @param Closure(): T $work
     * @param array<string, mixed> $context
     *
     * @return T
     */
    public function actingAs(OwnershipReleasingEngine $engine, AdminConnection $admin, array $roles, array $context, Closure $work): mixed
    {
        $taken = [];
        try {
            foreach ($roles as $role) {
                $this->scoped->run($admin, $engine->takeMembership($role), $context);
                $taken[] = $role;
            }

            $result = $work();
        } catch (DBConsoleException $e) {
            $this->giveBack($engine, $admin, $taken, $context, $e);

            throw $e;
        }

        $this->giveBack($engine, $admin, $taken, $context, null);

        return $result;
    }

    /**
     * Postgres returns booleans; other drivers and fakes return 1/0 or 't'/'f'.
     */
    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{0: list<Username>, 1: list<string>}
     */
    private function rolesToAssume(
        OwnerDelegatingEngine $engine,
        AdminConnection $admin,
        PrivilegeSet $set,
        DbName $db,
        Username $user,
        array $context,
    ): array {
        $toTake = [];
        $notCovered = [];

        foreach ($this->scoped->select($admin, $engine->rolesToAssume($set, $db, $user), $context) as $row) {
            $name = (string) $row['rolname'];
            if ($this->truthy($row['held'])) {
                continue;
            }

            if (! $this->truthy($row['can_admin'])) {
                $notCovered[] = $name;

                continue;
            }

            try {
                $toTake[] = new Username($name);
            } catch (InvalidIdentifier) {
                $notCovered[] = $name;
            }
        }

        return [$toTake, $notCovered];
    }

    /**
     * @param list<Username> $taken
     * @param array<string, mixed> $context
     */
    private function giveBack(
        OwnershipReleasingEngine $engine,
        AdminConnection $admin,
        array $taken,
        array $context,
        ?DBConsoleException $cause,
    ): void {
        $failed = [];
        $first = null;

        foreach (array_reverse($taken) as $role) {
            try {
                $this->scoped->run($admin, $engine->releaseMembership($role), $context);
            } catch (DBConsoleException $e) {
                $failed[] = $role->value;
                $first ??= $e;
            }
        }

        if ($first instanceof DBConsoleException) {
            throw RollbackFailed::whileCompensating(
                "give back membership in '" . implode("', '", $failed) . "'",
                [...$context, 'cause' => $cause?->code()->value],
                $first,
            );
        }
    }
}
