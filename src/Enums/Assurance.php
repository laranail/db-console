<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * One kind of assurance an operation can require, answered by the host
 * through OperatorAssurance. The values are what an `assurance_required`
 * response lists under `missing`, so a client knows where to send the operator.
 */
enum Assurance: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Second factor'), Description('The operator has enrolled in a second factor. The host sends them to enrol.')]
    case SecondFactor = 'second_factor';

    #[Label('Recent confirmation'), Description('The operator re-proved who they are within security.step_up.ttl. The host sends them to confirm.')]
    case RecentConfirmation = 'recent_confirmation';
}
