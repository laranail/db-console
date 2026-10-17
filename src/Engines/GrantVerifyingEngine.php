<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * An engine whose GRANT can succeed without granting. Postgres is one: when
 * the admin holds a privilege without the grant option (a schema it does not
 * own, a table another role owns), GRANT emits a warning, grants what it can
 * and reports success. The check reads the account's effective privileges
 * afterwards instead of trusting the GRANT.
 *
 * An engine implementing this has transactional DDL: PrivilegeManager runs
 * grant() and this check in one transaction on the target database, and
 * rolls the grant back when anything is missing.
 */
interface GrantVerifyingEngine
{
    /**
     * A read, scoped to $db, returning one row per privilege in $s that the
     * account does not hold: columns `object` and `privilege`. No rows means
     * the grant took effect.
     */
    public function missingGrants(PrivilegeSet $s, DbName $db, Username $u): StatementList;
}
