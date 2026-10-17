<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Domain\StatementList;

/**
 * The extra dialect an engine needs when an account cannot be dropped while
 * it owns objects or holds privileges. Postgres is that engine: DROP ROLE
 * refuses until REASSIGN OWNED and DROP OWNED have run inside every database
 * the role has anything in, and those two act only on the database the
 * session is connected to.
 */
interface OwnershipReleasingEngine
{
    /**
     * A read returning one row per database, other than the admin
     * connection's own, in which the account owns objects or holds
     * privileges. The database name is the row's first column; the second,
     * `can_connect`, says whether the admin may connect to it, so a drop can
     * refuse before changing anything.
     */
    public function dependentDatabases(Username $u): StatementList;

    /**
     * A read returning one boolean: whether the admin already has the
     * account's privileges, so taking membership would change nothing.
     */
    public function holdsPrivilegesOf(Username $u): StatementList;

    /**
     * Give the admin membership in the account, which releasing what the
     * account owns requires.
     */
    public function takeMembership(Username $u): StatementList;

    /**
     * Undo takeMembership(), for a drop that failed after taking it.
     */
    public function releaseMembership(Username $u): StatementList;

    /**
     * Release what the account owns inside each of $databases, scoped to
     * them. Each database's statements can run in one transaction.
     *
     * @param list<DbName> $databases
     */
    public function releaseOwnershipIn(Username $u, array $databases): StatementList;

    /**
     * Release what the account owns on the admin connection's own database
     * and drop it. Runs after releaseOwnershipIn() has committed: the drop
     * checks dependencies another session may still hold uncommitted.
     */
    public function dropReleasedAccount(Username $u, Host $h): StatementList;

    /**
     * The whole drop as one list: takeMembership(), releaseOwnershipIn() and
     * dropReleasedAccount(), in that order. Running it as one list cannot
     * give membership back if a step fails; OwnershipReleasingDrop runs the
     * parts separately and does.
     *
     * @param list<DbName> $databases
     */
    public function dropAccountReleasing(Username $u, Host $h, array $databases): StatementList;
}
