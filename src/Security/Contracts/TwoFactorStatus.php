<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Whether an operator has enrolled in two-factor authentication. db-console
 * stays headless: it never enrols anyone, it only reads what the host's
 * two-factor provider (laranail/authkit, Laravel Fortify) recorded.
 */
interface TwoFactorStatus
{
    /**
     * The operator has a confirmed second factor.
     */
    public function hasConfirmedTwoFactor(Authenticatable $user): bool;

    /**
     * The operator holds unused recovery codes. Enrolment only counts as
     * complete with them, so a lost device is never a lockout.
     */
    public function hasRecoveryCodes(Authenticatable $user): bool;
}
