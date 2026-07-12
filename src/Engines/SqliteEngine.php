<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\Capabilities;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\EncryptionCapabilities;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Exceptions\UnsupportedOperation;

/**
 * SQLite has no server-level account or privilege concept — a database is a
 * file and access is filesystem permissions. So this engine honestly
 * reports canCreateAccount: false and throws UnsupportedOperation for every
 * account/grant operation; the UI and CLI hide those features for SQLite
 * and offer database (file) management only. Degrading honestly beats
 * pretending or crashing at runtime.
 */
final class SqliteEngine implements Engine
{
    public function type(): EngineType
    {
        return EngineType::Sqlite;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            canCreateDatabase: true,
            canCreateAccount: false,
            canScopeAccountsByHost: false,
            canGrantTableLevel: false,
            canRotatePassword: false,
            encryption: new EncryptionCapabilities(
                canReadAtRestStatus: true,
                canRequireTlsOnAccount: false,
                atRestMechanism: 'SQLCipher (file)',
            ),
            accountModelNote: 'no accounts — file permissions',
        );
    }

    public function createDatabase(DbName $db, Charset $charset): StatementList
    {
        // A SQLite database is created by opening its file; there is no
        // CREATE DATABASE statement. File creation is handled by the service
        // layer against the configured path, not by an engine statement.
        return new StatementList;
    }

    public function dropDatabase(DbName $db): StatementList
    {
        // Dropping is deleting the file — a filesystem action, not SQL.
        return new StatementList;
    }

    public function listDatabases(): StatementList
    {
        return new StatementList;
    }

    public function createAccount(Username $u, Host $h, Password $p): StatementList
    {
        throw UnsupportedOperation::forEngine($this->type()->value, 'account creation');
    }

    public function dropAccount(Username $u, Host $h): StatementList
    {
        throw UnsupportedOperation::forEngine($this->type()->value, 'account deletion');
    }

    public function setPassword(Username $u, Host $h, Password $p): StatementList
    {
        throw UnsupportedOperation::forEngine($this->type()->value, 'password rotation');
    }

    public function listAccounts(): StatementList
    {
        throw UnsupportedOperation::forEngine($this->type()->value, 'account listing');
    }

    public function grant(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        throw UnsupportedOperation::forEngine($this->type()->value, 'granting privileges');
    }

    public function revoke(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        throw UnsupportedOperation::forEngine($this->type()->value, 'revoking privileges');
    }

    public function showGrants(Username $u, Host $h): StatementList
    {
        throw UnsupportedOperation::forEngine($this->type()->value, 'showing grants');
    }
}
