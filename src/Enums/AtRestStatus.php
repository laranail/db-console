<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * Read-only at-rest encryption status DBConsole displays per database.
 * DBConsole never enables or manages server-side encryption — this is a
 * readout, not a control.
 */
enum AtRestStatus: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Encrypted')]
    case Encrypted = 'encrypted';

    #[Label('Not encrypted')]
    case NotEncrypted = 'not_encrypted';

    #[Label('Unknown')]
    case Unknown = 'unknown';

    #[Label('Not supported')]
    case Unsupported = 'unsupported';
}
