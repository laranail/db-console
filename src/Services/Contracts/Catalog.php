<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Contracts;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Services\Results\AccountGrant;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * The catalog recording seam. Services record what they did through this
 * interface so they never depend on Eloquent directly. The catalog is
 * history and ownership metadata (who created what through DBConsole), not
 * the source of truth about the server — reads always go live.
 *
 * A5 provides the Eloquent-backed implementation; the core ships a NullCatalog
 * so the services work headless before persistence is wired.
 */
interface Catalog
{
    public function recordDatabase(string $server, DbName $db, string $charset, ?string $collation): void;

    public function forgetDatabase(string $server, DbName $db): void;

    public function recordAccount(string $server, Username $user, Host $host): void;

    public function forgetAccount(string $server, Username $user, Host $host): void;

    public function recordPasswordRotation(string $server, Username $user, Host $host): void;

    public function recordHostChange(string $server, Username $user, Host $oldHost, Host $newHost): void;

    public function recordGrant(string $server, Username $user, Host $host, DbName $db, PrivilegeSet $set): void;

    public function forgetGrant(string $server, Username $user, Host $host, DbName $db): void;

    /**
     * The catalog-recorded grants for an account, so a host change can
     * re-apply the same grants to the recreated account.
     *
     * @return list<AccountGrant>
     */
    public function grantsForAccount(string $server, Username $user, Host $host): array;
}
