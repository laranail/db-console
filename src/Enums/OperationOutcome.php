<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

enum OperationOutcome: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Succeeded')]
    case Succeeded = 'succeeded';

    #[Label('Failed')]
    case Failed = 'failed';

    #[Label('Rolled back')]
    case RolledBack = 'rolled_back';

    /**
     * The HTTP status an API response carries for this outcome.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Succeeded                => 200,
            self::Failed, self::RolledBack => 500,
        };
    }
}
