<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

/**
 * How far a grant reaches: a whole database or specific tables. Grants are
 * never server-wide by design.
 */
enum GrantScope: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Database')]
    case Database = 'database';

    #[Label('Table')]
    case Table = 'table';
}
