<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\StatementList;

/**
 * An engine whose admin account the doctor can inspect through the catalog
 * rather than SHOW GRANTS. Postgres is that engine: what a role may do is a
 * set of role attributes, not a list of grants.
 */
interface AdminInspectingEngine
{
    /**
     * A read returning one row about the admin connection's own role:
     * `is_superuser`, `can_create_db`, `can_create_role`,
     * `server_version_num`, and `server_port`, the port the server itself
     * listens on (null over a Unix socket).
     */
    public function adminProfile(): StatementList;

    /**
     * A read returning the name of each login role, other than the admin
     * and superusers, that the admin cannot administer (drop, rename,
     * change the password of), one row each.
     */
    public function unmanageableRoles(): StatementList;
}
