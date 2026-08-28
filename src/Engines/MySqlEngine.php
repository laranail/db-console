<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\Privilege;
use Simtabi\Laranail\DBConsole\Domain\Statement;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Domain\Capabilities;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Domain\EncryptionCapabilities;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * The MySQL account-management dialect. MariaDB shares it (MariaDbEngine
 * extends this and only overrides its type()), because their CREATE USER /
 * GRANT syntax is identical for everything DBConsole does.
 *
 * MySQL scopes accounts by host: 'user'@'host' is the account identity, so
 * every account operation takes both a Username and a Host.
 */
class MySqlEngine implements Engine, HostScopingEngine
{
    /**
     * Abstract Privilege → MySQL privilege keyword. Translation lives in one
     * place per engine, so the rest of the package never speaks a dialect.
     *
     * @var array<string, string>
     */
    private const array PRIVILEGE_MAP = [
        'select'                  => 'SELECT',
        'insert'                  => 'INSERT',
        'update'                  => 'UPDATE',
        'delete'                  => 'DELETE',
        'create'                  => 'CREATE',
        'alter'                   => 'ALTER',
        'drop'                    => 'DROP',
        'index'                   => 'INDEX',
        'references'              => 'REFERENCES',
        'create_temporary_tables' => 'CREATE TEMPORARY TABLES',
        'lock_tables'             => 'LOCK TABLES',
        'execute'                 => 'EXECUTE',
        'create_view'             => 'CREATE VIEW',
        'show_view'               => 'SHOW VIEW',
        'create_routine'          => 'CREATE ROUTINE',
        'alter_routine'           => 'ALTER ROUTINE',
        'event'                   => 'EVENT',
        'trigger'                 => 'TRIGGER',
    ];

    public function type(): EngineType
    {
        return EngineType::Mysql;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            canCreateDatabase: true,
            canCreateAccount: true,
            canScopeAccountsByHost: true,
            canGrantTableLevel: true,
            canRotatePassword: true,
            encryption: new EncryptionCapabilities(
                canReadAtRestStatus: true,
                canRequireTlsOnAccount: true,
                atRestMechanism: 'InnoDB keyring',
            ),
            accountModelNote: 'user@host',
        );
    }

    public function createDatabase(DbName $db, Charset $charset): StatementList
    {
        $q = Quoter::for($this->type());

        $sql = 'CREATE DATABASE ' . $q->identifier($db->value)
            . ' CHARACTER SET ' . $q->literal($charset->value);

        if ($charset->collation !== null) {
            $sql .= ' COLLATE ' . $q->literal($charset->collation);
        }

        return new StatementList(Statement::plain($sql));
    }

    public function dropDatabase(DbName $db): StatementList
    {
        $q = Quoter::for($this->type());

        return new StatementList(
            Statement::plain('DROP DATABASE ' . $q->identifier($db->value)),
        );
    }

    public function listDatabases(): StatementList
    {
        return new StatementList(Statement::plain('SHOW DATABASES'));
    }

    public function createAccount(Username $u, Host $h, Password $p): StatementList
    {
        $q = Quoter::for($this->type());
        $account = $this->account($q, $u, $h);

        return new StatementList(
            Statement::sensitive(
                sql: "CREATE USER {$account} IDENTIFIED BY " . $q->literal($p->reveal()),
                redacted: "CREATE USER {$account} IDENTIFIED BY '[redacted]'",
            ),
        );
    }

    public function dropAccount(Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());

        return new StatementList(
            Statement::plain('DROP USER ' . $this->account($q, $u, $h)),
        );
    }

    public function setPassword(Username $u, Host $h, Password $p): StatementList
    {
        $q = Quoter::for($this->type());
        $account = $this->account($q, $u, $h);

        return new StatementList(
            Statement::sensitive(
                sql: "ALTER USER {$account} IDENTIFIED BY " . $q->literal($p->reveal()),
                redacted: "ALTER USER {$account} IDENTIFIED BY '[redacted]'",
            ),
        );
    }

    public function listAccounts(): StatementList
    {
        return new StatementList(
            Statement::plain('SELECT User, Host FROM mysql.user ORDER BY User, Host'),
        );
    }

    public function grant(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());

        $sql = 'GRANT ' . $this->privilegeList($s)
            . ' ON ' . $q->identifier($db->value) . '.*'
            . ' TO ' . $this->account($q, $u, $h);

        return new StatementList(Statement::plain($sql));
    }

    public function revoke(PrivilegeSet $s, DbName $db, Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());

        $sql = 'REVOKE ' . $this->privilegeList($s)
            . ' ON ' . $q->identifier($db->value) . '.*'
            . ' FROM ' . $this->account($q, $u, $h);

        return new StatementList(Statement::plain($sql));
    }

    public function showGrants(Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());

        return new StatementList(
            Statement::plain('SHOW GRANTS FOR ' . $this->account($q, $u, $h)),
        );
    }

    public function readAuthentication(Username $u, Host $h): StatementList
    {
        $q = Quoter::for($this->type());

        // The hash is read as an uppercase hex literal so it can be re-fed to
        // CREATE USER ... AS 0x<hex>. The plaintext password is never involved.
        return new StatementList(
            Statement::plain(
                "SELECT plugin, CONCAT('0x', HEX(authentication_string)) AS auth"
                . ' FROM mysql.user'
                . ' WHERE User = ' . $q->literal($u->value)
                . ' AND Host = ' . $q->literal($h->value),
            ),
        );
    }

    public function createAccountWithAuth(Username $u, Host $h, string $plugin, string $hashLiteral): StatementList
    {
        $q = Quoter::for($this->type());
        $account = $this->account($q, $u, $h);
        // The plugin name is an identifier; the hash literal is already a
        // 0x... hex token produced by readAuthentication.
        $pluginQuoted = $q->identifier($plugin);

        return new StatementList(
            Statement::sensitive(
                sql: "CREATE USER {$account} IDENTIFIED WITH {$pluginQuoted} AS {$hashLiteral}",
                redacted: "CREATE USER {$account} IDENTIFIED WITH {$pluginQuoted} AS [redacted]",
            ),
        );
    }

    /**
     * Render the 'user'@'host' account identity. Both parts are already
     * allow-list-validated value objects; the Quoter's literal quoting is
     * belt-and-suspenders.
     */
    protected function account(Quoter $q, Username $u, Host $h): string
    {
        return $q->literal($u->value) . '@' . $q->literal($h->value);
    }

    /**
     * Translate the abstract privilege set to a comma-separated MySQL
     * privilege list.
     */
    protected function privilegeList(PrivilegeSet $set): string
    {
        return implode(', ', array_map(
            fn (Privilege $p): string => self::PRIVILEGE_MAP[$p->value],
            $set->privileges(),
        ));
    }
}
