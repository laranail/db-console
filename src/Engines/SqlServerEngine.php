<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Domain\Statement;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Domain\Capabilities;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Domain\EncryptionCapabilities;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * SQL Server splits accounts into a server-level LOGIN and a per-database
 * USER: create-account is a two-statement flow (CREATE LOGIN, then CREATE
 * USER in the database), and grants are per-database. Host scoping does not
 * apply. Charset/collation are set on the database.
 */
final class SqlServerEngine implements Engine
{
    /** @var array<string, string> */
    private const array PRIVILEGE_MAP = [
        'select'     => 'SELECT',
        'insert'     => 'INSERT',
        'update'     => 'UPDATE',
        'delete'     => 'DELETE',
        'references' => 'REFERENCES',
        'execute'    => 'EXECUTE',
        'create'     => 'CREATE TABLE',
        'alter'      => 'ALTER',
    ];

    public function type(): EngineType
    {
        return EngineType::Sqlsrv;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            canCreateDatabase: true,
            canCreateAccount: true,
            canScopeAccountsByHost: false,
            canGrantTableLevel: true,
            canRotatePassword: true,
            encryption: new EncryptionCapabilities(
                canReadAtRestStatus: true,
                canRequireTlsOnAccount: false,
                atRestMechanism: 'TDE',
            ),
            accountModelNote: 'login + user (two-step)',
        );
    }

    public function createDatabase(DbName $db, Charset $charset): StatementList
    {
        $q = Quoter::for($this->type());
        $sql = 'CREATE DATABASE ' . $q->identifier($db->value);

        if ($charset->collation !== null) {
            $sql .= ' COLLATE ' . $charset->collation;
        }

        return new StatementList(Statement::plain($sql));
    }

    public function dropDatabase(DbName $db): StatementList
    {
        $q = Quoter::for($this->type());

        return new StatementList(Statement::plain('DROP DATABASE ' . $q->identifier($db->value)));
    }

    public function listDatabases(): StatementList
    {
        return new StatementList(
            Statement::plain('SELECT name FROM sys.databases WHERE database_id > 4 ORDER BY name'),
        );
    }

    public function createAccount(Username $u, Host $h, Password $p): StatementList
    {
        $q = Quoter::for($this->type());
        $login = $q->identifier($u->value);

        return new StatementList(
            Statement::sensitive(
                sql: "CREATE LOGIN {$login} WITH PASSWORD = " . $q->literal($p->reveal()),
                redacted: "CREATE LOGIN {$login} WITH PASSWORD = '[redacted]'",
            ),
            Statement::plain("CREATE USER {$login} FOR LOGIN {$login}"),
        );
    }

    public function dropAccount(Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());
        $name = $q->identifier($u->value);

        return new StatementList(
            Statement::plain("DROP USER {$name}"),
            Statement::plain("DROP LOGIN {$name}"),
        );
    }

    public function setPassword(Username $u, Host $h, Password $p): StatementList
    {
        $q = Quoter::for($this->type());
        $login = $q->identifier($u->value);

        return new StatementList(
            Statement::sensitive(
                sql: "ALTER LOGIN {$login} WITH PASSWORD = " . $q->literal($p->reveal()),
                redacted: "ALTER LOGIN {$login} WITH PASSWORD = '[redacted]'",
            ),
        );
    }

    public function listAccounts(): StatementList
    {
        return new StatementList(
            Statement::plain("SELECT name FROM sys.server_principals WHERE type IN ('S','U') ORDER BY name"),
        );
    }

    public function grant(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());

        return new StatementList(
            Statement::plain('GRANT ' . $this->privilegeList($s) . ' TO ' . $q->identifier($u->value)),
        );
    }

    public function revoke(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());

        return new StatementList(
            Statement::plain('REVOKE ' . $this->privilegeList($s) . ' FROM ' . $q->identifier($u->value)),
        );
    }

    public function showGrants(Username $u, Host $h): StatementList
    {
        return new StatementList(
            Statement::plain(
                'SELECT permission_name, state_desc FROM sys.database_permissions p'
                . ' JOIN sys.database_principals pr ON p.grantee_principal_id = pr.principal_id'
                . ' WHERE pr.name = ' . Quoter::for($this->type())->literal($u->value),
            ),
        );
    }

    private function privilegeList(PrivilegeSet $set): string
    {
        $mapped = [];
        foreach ($set->privileges() as $privilege) {
            $mapped[self::PRIVILEGE_MAP[$privilege->value] ?? 'SELECT'] = true;
        }

        /** @var list<string> $keys */
        $keys = array_keys($mapped);

        return implode(', ', $keys);
    }
}
