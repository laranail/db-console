<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Engines\Engine;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Domain\Statement;
use Simtabi\Laranail\DBConsole\Domain\Capabilities;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Engines\AdminInspectingEngine;
use Simtabi\Laranail\DBConsole\Domain\EncryptionCapabilities;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * A Postgres admin profile answered by SQLite, so the doctor's judgement of
 * it runs without a server: a restricted admin on 17, behind a server that
 * says it listens on $serverPort.
 */
final readonly class FakeAdminProfileEngine implements AdminInspectingEngine, Engine
{
    public function __construct(private int $serverPort = 5432) {}

    public function adminProfile(): StatementList
    {
        return new StatementList(Statement::plain(
            'SELECT 0 AS is_superuser, 1 AS can_create_db, 1 AS can_create_role,'
            . " 170004 AS server_version_num, {$this->serverPort} AS server_port",
        ));
    }

    public function unmanageableRoles(): StatementList
    {
        return new StatementList(Statement::plain('SELECT 1 AS rolname WHERE 1 = 0'));
    }

    public function type(): EngineType
    {
        return EngineType::Pgsql;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(true, true, false, false, true, new EncryptionCapabilities(false, false));
    }

    public function createDatabase(DbName $db, Charset $charset): StatementList
    {
        return new StatementList;
    }

    public function dropDatabase(DbName $db): StatementList
    {
        return new StatementList;
    }

    public function listDatabases(): StatementList
    {
        return new StatementList;
    }

    public function createAccount(Username $u, Host $h, Password $p): StatementList
    {
        return new StatementList;
    }

    public function dropAccount(Username $u, Host $h): StatementList
    {
        return new StatementList;
    }

    public function setPassword(Username $u, Host $h, Password $p): StatementList
    {
        return new StatementList;
    }

    public function listAccounts(): StatementList
    {
        return new StatementList;
    }

    public function grant(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        return new StatementList;
    }

    public function revoke(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        return new StatementList;
    }

    public function showGrants(Username $u, Host $h): StatementList
    {
        return new StatementList;
    }
}
