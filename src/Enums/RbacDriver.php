<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

enum RbacDriver: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Built-in')]
    case Builtin = 'builtin';

    #[Label('Spatie laravel-permission')]
    case Spatie = 'spatie';
}
