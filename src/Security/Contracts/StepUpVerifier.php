<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Security\StepUpProof;

/**
 * Whether an operator has freshly proven it is them. One driver is bound,
 * chosen by `security.step_up.driver`; the StepUpGate decides when to ask.
 */
interface StepUpVerifier
{
    public function verified(Authenticatable $user, StepUpProof $proof): bool;
}
