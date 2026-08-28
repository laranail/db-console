<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

enum KmsProvider: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('AWS KMS')]
    case Aws = 'aws';

    #[Label('GCP KMS')]
    case Gcp = 'gcp';
}
