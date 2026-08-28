<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * The host-scope choice offered by the create-account wizard on engines
 * that scope accounts by host (MySQL/MariaDB).
 */
enum HostScopeMode: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Local host only')]
    case Localhost = 'localhost';

    #[Label('Any host')]
    case Any = 'any';

    #[Label('Specific host or pattern')]
    case Specific = 'specific';
}
