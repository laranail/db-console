<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\StatementList;

/**
 * An engine whose forced database drop can only end the sessions the admin is
 * allowed to end. Postgres is one: DROP DATABASE ... WITH (FORCE) terminates
 * only the sessions of roles whose privileges the admin has (or all
 * non-superuser sessions with pg_signal_backend), never a superuser's, and
 * fails if any remain.
 */
interface SessionEndingEngine
{
    /**
     * A read returning one row per role with a session on $db, other than the
     * admin's own: `rolname`, `superuser`, `held` (the admin may end its
     * sessions already) and `can_admin` (the admin may take membership in it).
     */
    public function sessionRoles(DbName $db): StatementList;
}
