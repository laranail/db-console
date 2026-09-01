<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

/**
 * The database families DBConsole manages. Direct connections only, by
 * design — there is no transport enum.
 */
enum EngineType: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('MySQL')]
    case Mysql = 'mysql';

    #[Label('MariaDB')]
    case Mariadb = 'mariadb';

    #[Label('PostgreSQL')]
    case Pgsql = 'pgsql';

    #[Label('SQL Server')]
    case Sqlsrv = 'sqlsrv';

    #[Label('SQLite')]
    case Sqlite = 'sqlite';

    /**
     * Whether this engine belongs to the MySQL family (shared dialect).
     */
    public function isMysqlFamily(): bool
    {
        return $this === self::Mysql || $this === self::Mariadb;
    }
}
