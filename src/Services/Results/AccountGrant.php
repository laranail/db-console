<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Results;

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * A catalog-recorded grant for an account: the database and the resolved
 * privilege set. Read back when a host change must re-apply the same grants
 * to the recreated account.
 */
final readonly class AccountGrant
{
    public function __construct(
        public DbName $database,
        public PrivilegeSet $privileges,
    ) {}
}
