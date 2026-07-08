<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

/**
 * The curated collation options offered by the create-database flow
 * (MySQL-family; other engines derive collation from the charset/locale).
 */
enum Collation: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('utf8mb4_unicode_ci')]
    case Utf8mb4UnicodeCi = 'utf8mb4_unicode_ci';

    #[Label('utf8mb4_general_ci')]
    case Utf8mb4GeneralCi = 'utf8mb4_general_ci';

    #[Label('utf8mb4_0900_ai_ci')]
    case Utf8mb40900AiCi = 'utf8mb4_0900_ai_ci';

    #[Label('utf8mb4_bin')]
    case Utf8mb4Bin = 'utf8mb4_bin';

    #[Label('utf8mb3_general_ci')]
    case Utf8mb3GeneralCi = 'utf8mb3_general_ci';

    #[Label('latin1_swedish_ci')]
    case Latin1SwedishCi = 'latin1_swedish_ci';

    #[Label('binary')]
    case Binary = 'binary';

    /**
     * The collations that belong to a given charset choice.
     *
     * @return list<self>
     */
    public static function forCharset(Charset $charset): array
    {
        return match ($charset) {
            Charset::Utf8mb4 => [
                self::Utf8mb4UnicodeCi, self::Utf8mb4GeneralCi,
                self::Utf8mb40900AiCi, self::Utf8mb4Bin,
            ],
            Charset::Utf8mb3 => [self::Utf8mb3GeneralCi],
            Charset::Latin1 => [self::Latin1SwedishCi],
            Charset::Binary => [self::Binary],
            default => [],
        };
    }
}
