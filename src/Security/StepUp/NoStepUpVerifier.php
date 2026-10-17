<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security\StepUp;

use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Security\StepUpProof;
use Simtabi\Laranail\DBConsole\Security\Contracts\StepUpVerifier;

/**
 * Step-up switched off (`security.step_up.driver = none`). An explicit opt-out
 * that doctor reports as an error in production.
 */
final class NoStepUpVerifier implements StepUpVerifier
{
    public function verified(Authenticatable $user, StepUpProof $proof): bool
    {
        return true;
    }
}
