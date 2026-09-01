<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

/**
 * TLS status of an admin connection, as reported by doctor and the
 * connection checks.
 */
enum TlsStatus: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Required and verified')]
    case RequiredOk = 'required_ok';

    #[Label('Off')]
    case Off = 'off';

    #[Label('On but unverified')]
    case Unverified = 'unverified';

    #[Label('Not applicable')]
    case NotApplicable = 'n/a';
}
