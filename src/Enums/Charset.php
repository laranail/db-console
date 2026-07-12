<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

/**
 * The curated character-set options offered by the create-database flow.
 * The Domain\Charset value object is the hard validation floor for what an
 * engine receives; this enum is the closed set the wizards and validation
 * offer, filtered per engine via forEngine().
 */
enum Charset: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('utf8mb4 (recommended)')]
    case Utf8mb4 = 'utf8mb4';

    #[Label('utf8mb3')]
    case Utf8mb3 = 'utf8mb3';

    #[Label('latin1')]
    case Latin1 = 'latin1';

    #[Label('ascii')]
    case Ascii = 'ascii';

    #[Label('binary')]
    case Binary = 'binary';

    #[Label('UTF8')]
    case Utf8 = 'utf8';

    #[Label('SQL_ASCII')]
    case SqlAscii = 'sql_ascii';

    /**
     * The charsets a given engine actually accepts.
     *
     * @return list<self>
     */
    public static function forEngine(EngineType $engine): array
    {
        return match ($engine) {
            EngineType::Mysql, EngineType::Mariadb => [
                self::Utf8mb4, self::Utf8mb3, self::Latin1, self::Ascii, self::Binary,
            ],
            EngineType::Pgsql => [self::Utf8, self::Latin1, self::SqlAscii],
            EngineType::Sqlsrv, EngineType::Sqlite => [self::Utf8],
        };
    }
}
