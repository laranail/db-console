<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * Where db-console reads whether an operator has confirmed two-factor
 * authentication (`security.two_factor.driver`).
 */
enum TwoFactorDriver: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Fortify'), Description('Reads two_factor_confirmed_at and two_factor_recovery_codes, the columns laranail/authkit and Laravel Fortify write.')]
    case Fortify = 'fortify';

    #[Label('None'), Description('No two-factor provider: nobody counts as enrolled.')]
    case Null = 'null';
}
