<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Contracts;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;

/**
 * A Catalog that can say what it already records. Optional, beside Catalog
 * rather than in it, so a host's own Catalog implementation keeps compiling.
 *
 * With it, an adoption knows whether it put the resource under management
 * for the first time (`newly_managed` on the result), and a failed wizard
 * run can take back exactly the adoptions it made. Without it, an adoption
 * reports `newly_managed: false` and a rollback leaves every record alone,
 * which is what db-console did before.
 */
interface TracksManagedResources
{
    public function managesAccount(string $server, Username $user, Host $host): bool;

    public function managesDatabase(string $server, DbName $db): bool;
}
