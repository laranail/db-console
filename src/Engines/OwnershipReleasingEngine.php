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
     * privileges. The database name is the row's first column.
     */
    public function dependentDatabases(Username $u): StatementList;

    /**
     * Drop the account after releasing what it owns inside each of
     * $databases (from dependentDatabases) and on the admin connection's own
     * database. Statements for $databases are scoped to them.
     *
     * @param list<DbName> $databases
     */
    public function dropAccountReleasing(Username $u, Host $h, array $databases): StatementList;
}
