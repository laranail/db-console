<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Domain\StatementList;

/**
 * The extra dialect an engine needs when an account's grants can only be
 * read from inside the database they are on. Postgres is that engine: grants
 * on schemas, tables and functions live in each database's own catalog, so a
 * read on the admin connection sees the admin's database and nothing else.
 */
interface DatabaseScopedGrantsEngine
{
    /**
     * A read returning one row per database, other than the admin
     * connection's own, in which the account may hold grants. The database
     * name is the row's first column; the second, `can_connect`, says
     * whether the admin may connect to read them.
     */
    public function grantDatabases(Username $u): StatementList;

    /**
     * Reads of the account's grants: the ones readable from anywhere, those
     * on the admin connection's own database, and those inside each of
     * $databases, scoped to it. Each row is the object kind, the database,
     * the object and the privilege, in that order.
     *
     * @param list<DbName> $databases
     */
    public function showGrantsIn(Username $u, array $databases): StatementList;
}
