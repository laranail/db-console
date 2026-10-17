<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

use Simtabi\Laranail\DBConsole\Engines\Engine;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Engines\MySqlEngine;

/**
 * The least-privilege admin db-console should be pointed at, per engine --
 * the statements doctor prints when it finds a root-like account. The
 * live-mysql CI job provisions its admin with exactly these, so this is the
 * grant proven against a real MySQL and MariaDB, not a guess.
 *
 * MySQL family: CREATE USER to create, alter and drop accounts, and every
 * privilege a preset can hand out WITH GRANT OPTION on *.*, since GRANT needs
 * both the privilege and GRANT OPTION on it. CREATE and DROP on *.* cover
 * creating and dropping databases, and SELECT covers the information_schema
 * and mysql.user reads. Nothing db-console runs needs RELOAD.
 */
final class MinimalAdminGrant
{
    public const string ACCOUNT = 'db_console_admin';

    /**
     * @return list<string>
     */
    public static function statements(Engine $engine): array
    {
        if ($engine->type() === EngineType::Pgsql) {
            return [PostgresAdminCheck::minimalRole()];
        }

        $account = "'" . self::ACCOUNT . "'@'%'";
        $privileges = $engine instanceof MySqlEngine
            ? $engine->grantablePrivileges()
            : (new MySqlEngine)->grantablePrivileges();

        return [
            "CREATE USER {$account} IDENTIFIED BY '...';",
            "GRANT CREATE USER ON *.* TO {$account};",
            'GRANT ' . implode(', ', $privileges) . " ON *.* TO {$account} WITH GRANT OPTION;",
        ];
    }
}
