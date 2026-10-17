<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * An engine where only an object's owner, or a role with the owner's
 * privileges, may grant on it, and where a default privilege belongs to the
 * role that creates the object. Postgres is one. Before a grant or revoke the
 * admin takes membership in the roles that own objects in the target (or can
 * create them), and gives it back afterwards.
 */
interface OwnerDelegatingEngine
{
    /**
     * A read, scoped to $db, returning one row per role the grant has to act
     * as: `rolname`, `held` (the admin already has its privileges) and
     * `can_admin` (the admin may take membership in it). Superusers are never
     * listed: nobody but a superuser can act as one.
     */
    public function rolesToAssume(PrivilegeSet $s, DbName $db, Username $u): StatementList;
}
