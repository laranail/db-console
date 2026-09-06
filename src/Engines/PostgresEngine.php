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
 * PostgreSQL uses roles, not user@host accounts: a login is a ROLE WITH
 * LOGIN, host scoping is pg_hba.conf's job (not something DBConsole edits),
 * and privileges are GRANTed ON DATABASE / ON ALL TABLES. The Host argument
 * is accepted for interface uniformity but does not appear in the SQL —
 * capabilities().canScopeAccountsByHost is false so the UI hides the field.
 */
final class PostgresEngine implements Engine
{
    /** @var array<string, string> */
    private const array PRIVILEGE_MAP = [
        'select'     => 'SELECT',
        'insert'     => 'INSERT',
        'update'     => 'UPDATE',
        'delete'     => 'DELETE',
        'references' => 'REFERENCES',
        'trigger'    => 'TRIGGER',
        // Postgres grants CREATE/TEMP at the database level and the rest at
        // table/schema level; the ones without a direct table-grant analogue
        // map to the closest standard privilege.
        'create'  => 'CREATE',
        'execute' => 'EXECUTE',
    ];

    public function type(): EngineType
    {
        return EngineType::Pgsql;
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
                atRestMechanism: 'pgcrypto / filesystem',
            ),
            accountModelNote: 'role (host scoping is pg_hba.conf)',
        );
    }

    public function createDatabase(DbName $db, Charset $charset): StatementList
    {
        $q = Quoter::for($this->type());

        return new StatementList(
            Statement::plain(
                'CREATE DATABASE ' . $q->identifier($db->value)
                . ' ENCODING ' . $q->literal(strtoupper($charset->value)),
            ),
        );
    }

    public function dropDatabase(DbName $db): StatementList
    {
        $q = Quoter::for($this->type());

        return new StatementList(Statement::plain('DROP DATABASE ' . $q->identifier($db->value)));
    }

    public function listDatabases(): StatementList
    {
        return new StatementList(
            Statement::plain('SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname'),
        );
    }

    public function createAccount(Username $u, Host $h, Password $p): StatementList
    {
        $q = Quoter::for($this->type());
        $role = $q->identifier($u->value);

        return new StatementList(
            Statement::sensitive(
                sql: "CREATE ROLE {$role} WITH LOGIN PASSWORD " . $q->literal($p->reveal()),
                redacted: "CREATE ROLE {$role} WITH LOGIN PASSWORD '[redacted]'",
            ),
        );
    }

    public function dropAccount(Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());

        return new StatementList(Statement::plain('DROP ROLE ' . $q->identifier($u->value)));
    }

    public function setPassword(Username $u, Host $h, Password $p): StatementList
    {
        $q = Quoter::for($this->type());
        $role = $q->identifier($u->value);

        return new StatementList(
            Statement::sensitive(
                sql: "ALTER ROLE {$role} WITH PASSWORD " . $q->literal($p->reveal()),
                redacted: "ALTER ROLE {$role} WITH PASSWORD '[redacted]'",
            ),
        );
    }

    public function listAccounts(): StatementList
    {
        return new StatementList(
            Statement::plain('SELECT rolname FROM pg_roles WHERE rolcanlogin = true ORDER BY rolname'),
        );
    }

    public function grant(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());
        $role = $q->identifier($u->value);

        return new StatementList(
            Statement::plain('GRANT CONNECT ON DATABASE ' . $q->identifier($db->value) . " TO {$role}"),
            Statement::plain(
                'GRANT ' . $this->privilegeList($s) . " ON ALL TABLES IN SCHEMA public TO {$role}",
            ),
        );
    }

    public function revoke(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());
        $role = $q->identifier($u->value);

        return new StatementList(
            Statement::plain(
                'REVOKE ' . $this->privilegeList($s) . " ON ALL TABLES IN SCHEMA public FROM {$role}",
            ),
        );
    }

    public function showGrants(Username $u, Host $h): StatementList
    {
        return new StatementList(
            Statement::plain(
                'SELECT table_schema, table_name, privilege_type FROM information_schema.role_table_grants'
                . ' WHERE grantee = ' . Quoter::for($this->type())->literal($u->value),
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
