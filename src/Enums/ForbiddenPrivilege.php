<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

/**
 * Privileges DBConsole refuses to grant, ever: self-escalation (GRANT
 * OPTION and friends) and server-wide power (SUPER, FILE, CREATEROLE,
 * sysadmin, ...). The domain guard reads this list through
 * Privilege::forbidden(); nothing else defines what is forbidden.
 */
enum ForbiddenPrivilege: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    // Self-escalation (any engine)
    #[Label('GRANT OPTION')]
    case GrantOption = 'grant_option';

    #[Label('WITH ADMIN OPTION')]
    case AdminOption = 'admin_option';

    // MySQL / MariaDB server-wide
    #[Label('SUPER')]
    case Super = 'super';

    #[Label('FILE')]
    case File = 'file';

    #[Label('PROCESS')]
    case Process = 'process';

    #[Label('SHUTDOWN')]
    case Shutdown = 'shutdown';

    #[Label('RELOAD')]
    case Reload = 'reload';

    #[Label('CREATE USER')]
    case CreateUser = 'create_user';

    #[Label('REPLICATION CLIENT')]
    case ReplicationClient = 'replication_client';

    #[Label('REPLICATION SLAVE')]
    case ReplicationSlave = 'replication_slave';

    // PostgreSQL role attributes
    #[Label('SUPERUSER')]
    case Superuser = 'superuser';

    #[Label('CREATEROLE')]
    case CreateRole = 'createrole';

    #[Label('CREATEDB')]
    case CreateDb = 'createdb';

    #[Label('REPLICATION')]
    case Replication = 'replication';

    #[Label('BYPASSRLS')]
    case BypassRls = 'bypassrls';

    // SQL Server server roles / permissions
    #[Label('sysadmin')]
    case Sysadmin = 'sysadmin';

    #[Label('securityadmin')]
    case SecurityAdmin = 'securityadmin';

    #[Label('serveradmin')]
    case ServerAdmin = 'serveradmin';

    #[Label('CONTROL SERVER')]
    case ControlServer = 'control_server';

    /**
     * Parse loose operator input ("GRANT OPTION", "with grant option",
     * "SUPER") into a forbidden case, or null when the token is not on the
     * block list.
     */
    public static function tryFromLoose(string $input): ?self
    {
        $token = Privilege::normalizeToken($input);

        // "WITH GRANT OPTION" and "WITH ADMIN OPTION" normalize with the
        // leading WITH; strip it so both spellings hit the same case.
        $token = (string) preg_replace('/^with_/', '', $token);

        return self::tryFrom($token);
    }
}
