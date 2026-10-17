<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security\TwoFactor;

use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Security\Contracts\TwoFactorStatus;

/**
 * No two-factor provider. Nobody counts as enrolled, so with enforcement on
 * every operator is refused after the grace window; doctor reports this
 * combination as an error rather than letting it surface as a lockout.
 */
final class NullTwoFactorStatus implements TwoFactorStatus
{
    public function hasConfirmedTwoFactor(Authenticatable $user): bool
    {
        return false;
    }

    public function hasRecoveryCodes(Authenticatable $user): bool
    {
        return false;
    }
}
