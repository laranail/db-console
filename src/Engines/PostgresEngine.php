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
use Simtabi\Laranail\DBConsole\Exceptions\UnknownPrivilege;
use Simtabi\Laranail\DBConsole\Domain\EncryptionCapabilities;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * PostgreSQL uses roles, not user@host accounts: a login is a ROLE WITH
 * LOGIN, host scoping is pg_hba.conf's job (not something DBConsole edits),
 * and privileges are GRANTed ON DATABASE / ON ALL TABLES. The Host argument
 * is accepted for interface uniformity but does not appear in the SQL —
 * capabilities().canScopeAccountsByHost is false so the UI hides the field.
 *
 * Schema grants, table grants, default privileges and ownership release act
 * on the database the session is connected to, so those statements are
 * scoped to the target database (Statement::inDatabase) and run there.
 */
final class PostgresEngine implements EmptinessProbingEngine, Engine, OwnershipReleasingEngine
{
    /**
     * Where each abstract privilege is granted in Postgres, by object kind.
     * Every Privilege case must have an entry: an unclassified privilege
     * throws rather than turning into something else.
     *
     * An empty list is a privilege Postgres does not grant: ALTER, DROP and
     * CREATE INDEX belong to an object's owner, and the role owns what it
     * creates with the schema CREATE below; LOCK is allowed by the table
     * privileges; MySQL's event scheduler has no Postgres counterpart.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array PRIVILEGE_TARGETS = [
        'select'                  => ['tables' => ['SELECT'], 'sequences' => ['SELECT']],
        'show_view'               => ['tables' => ['SELECT']],
        'insert'                  => ['tables' => ['INSERT'], 'sequences' => ['USAGE']],
        'update'                  => ['tables' => ['UPDATE'], 'sequences' => ['USAGE']],
        'delete'                  => ['tables' => ['DELETE']],
        'references'              => ['tables' => ['REFERENCES']],
        'trigger'                 => ['tables' => ['TRIGGER']],
        'create'                  => ['schema' => ['CREATE']],
        'create_view'             => ['schema' => ['CREATE']],
        'create_routine'          => ['schema' => ['CREATE']],
        'create_temporary_tables' => ['database' => ['TEMPORARY']],
        'execute'                 => ['functions' => ['EXECUTE']],
        'alter'                   => [],
        'drop'                    => [],
        'index'                   => [],
        'alter_routine'           => [],
        'lock_tables'             => [],
        'event'                   => [],
    ];

    /**
     * The object kinds a schema-wide grant covers, with the keyword each
     * takes after ALL ... IN SCHEMA and in ALTER DEFAULT PRIVILEGES.
     *
     * @var array<string, string>
     */
    private const array SCHEMA_OBJECTS = [
        'tables'    => 'TABLES',
        'sequences' => 'SEQUENCES',
        'functions' => 'FUNCTIONS',
    ];

    /**
     * MySQL character-set names mapped to the Postgres encoding they mean.
     * The package default is utf8mb4, which Postgres has no encoding for.
     *
     * @var array<string, string>
     */
    private const array ENCODINGS = [
        'utf8mb4'   => 'UTF8',
        'utf8mb3'   => 'UTF8',
        'utf8'      => 'UTF8',
        'ascii'     => 'SQL_ASCII',
        'sql_ascii' => 'SQL_ASCII',
        'latin1'    => 'LATIN1',
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
                . ' ENCODING ' . $q->literal(self::ENCODINGS[strtolower($charset->value)] ?? strtoupper($charset->value)),
            ),
        );
    }

    public function dropDatabase(DbName $db): StatementList
    {
        $q = Quoter::for($this->type());

        // FORCE (PG13+) terminates the sessions still connected, which a
        // managed service's monitoring and any pooler always hold.
        return new StatementList(Statement::plain('DROP DATABASE ' . $q->identifier($db->value) . ' WITH (FORCE)'));
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
        return $this->dropAccountReleasing($u, $h, []);
    }

    public function dependentDatabases(Username $u): StatementList
    {
        $q = Quoter::for($this->type());

        return new StatementList(
            Statement::plain(
                'SELECT DISTINCT d.datname FROM pg_catalog.pg_shdepend s'
                . ' JOIN pg_catalog.pg_database d ON d.oid = s.dbid'
                . " WHERE s.refclassid = 'pg_catalog.pg_authid'::regclass"
                . ' AND s.refobjid = (SELECT oid FROM pg_catalog.pg_roles WHERE rolname = ' . $q->literal($u->value) . ')'
                . ' AND d.datname <> current_database()'
                . ' ORDER BY d.datname',
            ),
        );
    }

    public function dropAccountReleasing(Username $u, Host $h, array $databases): StatementList
    {
        $q = Quoter::for($this->type());
        $role = $q->identifier($u->value);
        $release = ["REASSIGN OWNED BY {$role} TO CURRENT_USER", "DROP OWNED BY {$role}"];

        // REASSIGN/DROP OWNED need the privileges of the role. On PG16+ a
        // CREATEROLE admin holds ADMIN OPTION on the roles it created but
        // does not inherit them, so it takes membership first.
        $statements = [Statement::plain("GRANT {$role} TO CURRENT_USER")];

        foreach ($databases as $db) {
            foreach ($release as $sql) {
                $statements[] = Statement::plain($sql)->inDatabase($db);
            }
        }

        // On the admin's own database this also revokes the role's
        // privileges on shared objects, such as CONNECT on a database.
        foreach ($release as $sql) {
            $statements[] = Statement::plain($sql);
        }

        $statements[] = Statement::plain("DROP ROLE {$role}");

        return new StatementList(...$statements);
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
        $targets = $this->targets($s);
        $inTarget = static fn (string $sql): Statement => Statement::plain($sql)->inDatabase($db);

        // Database-level grants are recorded in the shared catalog and run
        // from anywhere; everything below acts on the connected database.
        $statements = [
            Statement::plain(
                'GRANT ' . implode(', ', ['CONNECT', ...$targets['database']])
                . ' ON DATABASE ' . $q->identifier($db->value) . " TO {$role}",
            ),
            $inTarget('GRANT ' . implode(', ', ['USAGE', ...$targets['schema']]) . " ON SCHEMA public TO {$role}"),
        ];

        foreach (self::SCHEMA_OBJECTS as $kind => $keyword) {
            if ($targets[$kind] !== []) {
                $statements[] = $inTarget('GRANT ' . implode(', ', $targets[$kind]) . " ON ALL {$keyword} IN SCHEMA public TO {$role}");
            }
        }

        // Objects the admin creates later (a migration it runs) are covered
        // too, not only the ones that exist now.
        foreach (self::SCHEMA_OBJECTS as $kind => $keyword) {
            if ($targets[$kind] !== []) {
                $statements[] = $inTarget(
                    'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ' . implode(', ', $targets[$kind]) . " ON {$keyword} TO {$role}",
                );
            }
        }

        return new StatementList(...$statements);
    }

    public function revoke(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());
        $role = $q->identifier($u->value);
        $targets = $this->targets($s);
        $inTarget = static fn (string $sql): Statement => Statement::plain($sql)->inDatabase($db);

        // CONNECT and schema USAGE are not revoked: every set implies them,
        // so revoking a subset must not strip the rest.
        $statements = [];
        if ($targets['database'] !== []) {
            $statements[] = Statement::plain(
                'REVOKE ' . implode(', ', $targets['database']) . ' ON DATABASE ' . $q->identifier($db->value) . " FROM {$role}",
            );
        }

        if ($targets['schema'] !== []) {
            $statements[] = $inTarget('REVOKE ' . implode(', ', $targets['schema']) . " ON SCHEMA public FROM {$role}");
        }

        foreach (self::SCHEMA_OBJECTS as $kind => $keyword) {
            if ($targets[$kind] !== []) {
                $statements[] = $inTarget('REVOKE ' . implode(', ', $targets[$kind]) . " ON ALL {$keyword} IN SCHEMA public FROM {$role}");
            }
        }

        foreach (self::SCHEMA_OBJECTS as $kind => $keyword) {
            if ($targets[$kind] !== []) {
                $statements[] = $inTarget(
                    'ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ' . implode(', ', $targets[$kind]) . " ON {$keyword} FROM {$role}",
                );
            }
        }

        return new StatementList(...$statements);
    }

    public function countUserObjects(DbName $db): StatementList
    {
        return new StatementList(
            Statement::plain(
                'SELECT COUNT(*) FROM pg_catalog.pg_class c'
                . ' JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace'
                . " WHERE c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f')"
                . " AND n.nspname NOT IN ('pg_catalog', 'information_schema')"
                . " AND n.nspname NOT LIKE 'pg\\_%'",
            )->inDatabase($db),
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

    /**
     * The set's privileges grouped by the object Postgres grants them on,
     * deduplicated, in the set's order.
     *
     * @return array{database: list<string>, schema: list<string>, tables: list<string>, sequences: list<string>, functions: list<string>}
     */
    private function targets(PrivilegeSet $set): array
    {
        $targets = ['database' => [], 'schema' => [], 'tables' => [], 'sequences' => [], 'functions' => []];

        foreach ($set->privileges() as $privilege) {
            foreach ($this->classify($privilege->value) as $kind => $grants) {
                foreach ($grants as $grant) {
                    $targets[$kind][$grant] = $grant;
                }
            }
        }

        return [
            'database'  => array_values($targets['database']),
            'schema'    => array_values($targets['schema']),
            'tables'    => array_values($targets['tables']),
            'sequences' => array_values($targets['sequences']),
            'functions' => array_values($targets['functions']),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function classify(string $privilege): array
    {
        if (! array_key_exists($privilege, self::PRIVILEGE_TARGETS)) {
            throw UnknownPrivilege::forToken($privilege);
        }

        return self::PRIVILEGE_TARGETS[$privilege];
    }
}
