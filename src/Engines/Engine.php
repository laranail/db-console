<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\Capabilities;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\EngineType;

/**
 * One database family's account-management dialect. This is the ONLY layer
 * that interpolates identifiers into SQL (via a Quoter, on top of already
 * allow-listed value objects) and the only place that produces a statement
 * string. Services never build SQL.
 *
 * Every method returns a StatementList rather than executing anything, so
 * the same statements can be previewed ("show SQL"), audited (redacted),
 * and run by the admin connection — the engine has no connection of its own.
 */
interface Engine
{
    public function type(): EngineType;

    public function capabilities(): Capabilities;

    public function createDatabase(DbName $db, Charset $charset): StatementList;

    public function dropDatabase(DbName $db): StatementList;

    public function listDatabases(): StatementList;

    public function createAccount(Username $u, Host $h, Password $p): StatementList;

    public function dropAccount(Username $u, Host $h): StatementList;

    public function setPassword(Username $u, Host $h, Password $p): StatementList;

    public function listAccounts(): StatementList;

    public function grant(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList;

    public function revoke(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList;

    public function showGrants(Username $u, Host $h): StatementList;
}
