<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Catalog;

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Services\Contracts\Catalog;

/**
 * A no-op catalog used when persistence is not wired (headless use before
 * the Eloquent catalog is bound). Every service still works against the
 * live server; only the audit/ownership history is skipped.
 */
final class NullCatalog implements Catalog
{
    public function recordDatabase(string $server, DbName $db, string $charset, ?string $collation): void {}

    public function forgetDatabase(string $server, DbName $db): void {}

    public function recordAccount(string $server, Username $user, Host $host): void {}

    public function forgetAccount(string $server, Username $user, Host $host): void {}

    public function recordPasswordRotation(string $server, Username $user, Host $host): void {}

    public function recordHostChange(string $server, Username $user, Host $oldHost, Host $newHost): void {}

    public function recordGrant(string $server, Username $user, Host $host, DbName $db, PrivilegeSet $set): void {}

    public function forgetGrant(string $server, Username $user, Host $host, DbName $db): void {}

    public function grantsForAccount(string $server, Username $user, Host $host): array
    {
        return [];
    }
}
