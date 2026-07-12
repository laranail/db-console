<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

enum VaultAuthMethod: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('AppRole')]
    case AppRole = 'approle';

    #[Label('Token')]
    case Token = 'token';
}
