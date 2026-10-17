<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\StatementList;

/**
 * An engine whose "is this database empty" check has to run inside the
 * database itself, because its catalog is per database. Postgres is one:
 * pg_class on the admin connection describes the admin's database, not the
 * target. The MySQL family reads information_schema from anywhere and does
 * not need this.
 */
interface EmptinessProbingEngine
{
    /**
     * A read returning a single count of user-created relations in $db,
     * scoped to $db. Zero means empty.
     */
    public function countUserObjects(DbName $db): StatementList;
}
