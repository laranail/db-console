<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

enum ApiAuthGuard: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Sanctum')]
    case Sanctum = 'sanctum';

    #[Label('Passport')]
    case Passport = 'passport';
}
