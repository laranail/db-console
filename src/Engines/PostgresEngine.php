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
final class PostgresEngine implements DatabaseScopedGrantsEngine, EmptinessProbingEngine, Engine, GrantVerifyingEngine, OwnershipReleasingEngine
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
        $encoding = self::ENCODINGS[strtolower($charset->value)] ?? strtoupper($charset->value);

        if ($encoding === 'UTF8') {
            return new StatementList(
                Statement::plain('CREATE DATABASE ' . $q->identifier($db->value) . ' ENCODING ' . $q->literal($encoding)),
            );
        }

        // CREATE DATABASE copies template1, which is UTF8 on every image and
        // managed service, and a copy keeps its template's encoding. Only
        // template0 may be copied into another one, and the cluster's locale
        // (en_US.utf8) is valid for UTF8 alone; C is valid for every encoding.
        return new StatementList(
            Statement::plain(
                'CREATE DATABASE ' . $q->identifier($db->value)
                . ' TEMPLATE template0 ENCODING ' . $q->literal($encoding)
                . " LC_COLLATE 'C' LC_CTYPE 'C'",
            ),
        );
    }

    public function dropDatabase(DbName $db): StatementList
    {
        $q = Quoter::for($this->type());

        // FORCE (PG13+) terminates the sessions still connected that the
        // admin may signal: its own, and those of roles whose privileges it
        // holds. A session of a role it cannot signal still blocks the drop.
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
                "SELECT DISTINCT d.datname, has_database_privilege(d.datname, 'CONNECT') AS can_connect"
                . ' FROM pg_catalog.pg_shdepend s'
                . ' JOIN pg_catalog.pg_database d ON d.oid = s.dbid'
                . " WHERE s.refclassid = 'pg_catalog.pg_authid'::regclass"
                . ' AND s.refobjid = (SELECT oid FROM pg_catalog.pg_roles WHERE rolname = ' . $q->literal($u->value) . ')'
                . ' AND d.datname <> current_database()'
                . ' ORDER BY d.datname',
            ),
        );
    }

    public function holdsPrivilegesOf(Username $u): StatementList
    {
        $q = Quoter::for($this->type());

        // USAGE: the role's privileges are available without SET ROLE, which
        // is what REASSIGN OWNED and DROP OWNED check. True for a superuser.
        return new StatementList(Statement::plain('SELECT pg_has_role(' . $q->literal($u->value) . ", 'USAGE')"));
    }

    public function takeMembership(Username $u): StatementList
    {
        // On PG16+ a CREATEROLE admin holds ADMIN OPTION on the roles it
        // created but does not inherit them; before 16 CREATEROLE can grant
        // any non-superuser role. Either way this grants what the release
        // needs. The membership inherits when the admin role does.
        return new StatementList(Statement::plain('GRANT ' . Quoter::for($this->type())->identifier($u->value) . ' TO CURRENT_USER'));
    }

    public function releaseMembership(Username $u): StatementList
    {
        return new StatementList(Statement::plain('REVOKE ' . Quoter::for($this->type())->identifier($u->value) . ' FROM CURRENT_USER'));
    }

    public function releaseOwnershipIn(Username $u, array $databases): StatementList
    {
        $statements = [];
        foreach ($databases as $db) {
            foreach ($this->release($u) as $sql) {
                $statements[] = Statement::plain($sql)->inDatabase($db);
            }
        }

        return new StatementList(...$statements);
    }

    public function dropReleasedAccount(Username $u, Host $h): StatementList
    {
        // On the admin's own database the release also revokes the role's
        // privileges on shared objects, such as CONNECT on a database.
        $statements = array_map(Statement::plain(...), $this->release($u));
        $statements[] = Statement::plain('DROP ROLE ' . Quoter::for($this->type())->identifier($u->value));

        return new StatementList(...$statements);
    }

    public function dropAccountReleasing(Username $u, Host $h, array $databases): StatementList
    {
        return new StatementList(
            ...$this->takeMembership($u)->all(),
            ...$this->releaseOwnershipIn($u, $databases)->all(),
            ...$this->dropReleasedAccount($u, $h)->all(),
        );
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

        // Database-level grants could run from anywhere; they run in the
        // target too, so the whole grant shares one connection and one
        // transaction with its verification (GrantVerifyingEngine).
        $statements = [
            $inTarget(
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
        // too, not only the ones that exist now. Default privileges are per
        // creating role, so tables another role creates are not covered.
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
        // so revoking a subset must not strip the rest. Several abstract
        // privileges share one Postgres privilege (select and show_view are
        // both SELECT; create, create_view and create_routine are all schema
        // CREATE; insert and update both need sequence USAGE). Revoking any of
        // them revokes the shared privilege: the alternative leaves the role
        // holding access it was meant to lose.
        $statements = [];
        if ($targets['database'] !== []) {
            $statements[] = $inTarget(
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

    public function missingGrants(PrivilegeSet $s, DbName $db, Username $u): StatementList
    {
        $q = Quoter::for($this->type());
        $role = $q->literal($u->value);
        $database = $q->literal($db->value);
        $targets = $this->targets($s);
        $privileges = static fn (array $list): string => 'ARRAY[' . implode(', ', array_map($q->literal(...), $list)) . ']';

        // One SELECT per object kind the set touches, each yielding the
        // (object, privilege) pairs the role lacks. Effective privileges
        // count, including ones held through PUBLIC or ownership.
        $checks = [
            "SELECT 'database ' || {$database} AS object, p AS privilege"
            . ' FROM unnest(' . $privileges(['CONNECT', ...$targets['database']]) . ') AS p'
            . " WHERE NOT has_database_privilege({$role}, {$database}, p)",
            "SELECT 'schema public' AS object, p AS privilege"
            . ' FROM unnest(' . $privileges(['USAGE', ...$targets['schema']]) . ') AS p'
            . " WHERE NOT has_schema_privilege({$role}, 'public', p)",
        ];

        $relations = [
            'tables'    => ["'r', 'p', 'v', 'm', 'f'", 'table', 'has_table_privilege'],
            'sequences' => ["'S'", 'sequence', 'has_sequence_privilege'],
        ];
        foreach ($relations as $kind => [$relkinds, $label, $function]) {
            if ($targets[$kind] !== []) {
                $checks[] = "SELECT '{$label} ' || quote_ident(c.relname) AS object, p AS privilege"
                    . ' FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace'
                    . ' CROSS JOIN unnest(' . $privileges($targets[$kind]) . ') AS p'
                    . " WHERE n.nspname = 'public' AND c.relkind IN ({$relkinds})"
                    . " AND NOT {$function}({$role}, c.oid, p)";
            }
        }

        if ($targets['functions'] !== []) {
            $checks[] = "SELECT 'function ' || quote_ident(f.proname) AS object, p AS privilege"
                . ' FROM pg_catalog.pg_proc f JOIN pg_catalog.pg_namespace n ON n.oid = f.pronamespace'
                . ' CROSS JOIN unnest(' . $privileges($targets['functions']) . ') AS p'
                . " WHERE n.nspname = 'public' AND f.prokind = 'f'"
                . " AND NOT has_function_privilege({$role}, f.oid, p)";
        }

        return new StatementList(Statement::plain(implode(' UNION ALL ', $checks) . ' ORDER BY 1, 2')->inDatabase($db));
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
        return $this->showGrantsIn($u, []);
    }

    public function grantDatabases(Username $u): StatementList
    {
        // Grants on objects inside a database are recorded in the shared
        // catalog against that database, like ownership, so the databases a
        // drop must release in are the ones whose grants are worth reading.
        return $this->dependentDatabases($u);
    }

    public function showGrantsIn(Username $u, array $databases): StatementList
    {
        $q = Quoter::for($this->type());
        $grantee = '(SELECT oid FROM pg_catalog.pg_roles WHERE rolname = ' . $q->literal($u->value) . ')';

        // Grants on databases sit in the shared catalog and read the same
        // from anywhere. Explicit grants only: what a role holds through
        // PUBLIC or by owning an object has no ACL entry naming it.
        $statements = [
            Statement::plain(
                "SELECT 'database' AS object_type, d.datname::text AS database_name, d.datname::text AS object_name, a.privilege_type"
                . ' FROM pg_catalog.pg_database d CROSS JOIN LATERAL aclexplode(d.datacl) AS a'
                . " WHERE a.grantee = {$grantee}"
                . ' ORDER BY 2, 4',
            ),
            Statement::plain($this->objectGrants($grantee)),
        ];

        foreach ($databases as $db) {
            $statements[] = Statement::plain($this->objectGrants($grantee))->inDatabase($db);
        }

        return new StatementList(...$statements);
    }

    /**
     * Grants on schemas, relations, functions and default privileges in the
     * database the session is connected to.
     */
    private function objectGrants(string $grantee): string
    {
        $database = 'current_database()::text';

        return implode(' UNION ALL ', [
            "SELECT 'schema' AS object_type, {$database} AS database_name, n.nspname::text AS object_name, a.privilege_type"
            . ' FROM pg_catalog.pg_namespace n CROSS JOIN LATERAL aclexplode(n.nspacl) AS a'
            . " WHERE a.grantee = {$grantee}",
            "SELECT CASE c.relkind WHEN 'S' THEN 'sequence' WHEN 'v' THEN 'view' WHEN 'm' THEN 'materialized view'"
            . " WHEN 'f' THEN 'foreign table' ELSE 'table' END, {$database}, n.nspname || '.' || c.relname, a.privilege_type"
            . ' FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace'
            . ' CROSS JOIN LATERAL aclexplode(c.relacl) AS a'
            . " WHERE c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f') AND a.grantee = {$grantee}",
            "SELECT 'function', {$database}, n.nspname || '.' || p.proname || '(' || pg_catalog.pg_get_function_identity_arguments(p.oid) || ')', a.privilege_type"
            . ' FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace'
            . ' CROSS JOIN LATERAL aclexplode(p.proacl) AS a'
            . " WHERE a.grantee = {$grantee}",
            "SELECT 'default privileges', {$database}, CASE da.defaclobjtype WHEN 'r' THEN 'tables' WHEN 'S' THEN 'sequences'"
            . " WHEN 'f' THEN 'functions' WHEN 'T' THEN 'types' WHEN 'n' THEN 'schemas' ELSE da.defaclobjtype::text END"
            . " || COALESCE(' in schema ' || n.nspname, ''), a.privilege_type"
            . ' FROM pg_catalog.pg_default_acl da LEFT JOIN pg_catalog.pg_namespace n ON n.oid = da.defaclnamespace'
            . ' CROSS JOIN LATERAL aclexplode(da.defaclacl) AS a'
            . " WHERE a.grantee = {$grantee}",
        ]) . ' ORDER BY 1, 2, 3, 4';
    }

    /**
     * @return list<string>
     */
    private function release(Username $u): array
    {
        $role = Quoter::for($this->type())->identifier($u->value);

        return ["REASSIGN OWNED BY {$role} TO CURRENT_USER", "DROP OWNED BY {$role}"];
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
