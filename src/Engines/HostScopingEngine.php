<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Domain\StatementList;

/**
 * The extra dialect an engine needs to support a grant-preserving host
 * change (section 15) — only MySQL-family engines scope accounts by host, so
 * only they implement this. It reads the existing account's stored
 * authentication (its plugin + password HASH, never the plaintext) and can
 * recreate the account at a new host with the same hash, so the password is
 * preserved without DBConsole ever knowing it.
 */
interface HostScopingEngine
{
    /**
     * A statement that reads the account's auth plugin and password hash (as
     * a re-usable hex literal). Runs as a read; the result feeds
     * createAccountWithAuth.
     */
    public function readAuthentication(Username $u, Host $h): StatementList;

    /**
     * Recreate the account at a host using an existing auth plugin + hash
     * (from readAuthentication). The hash is treated as sensitive: the
     * statement's display form redacts it.
     */
    public function createAccountWithAuth(Username $u, Host $h, string $plugin, string $hashLiteral): StatementList;
}
