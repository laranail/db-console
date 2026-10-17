<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security\TwoFactor;

use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Security\Contracts\TwoFactorStatus;

/**
 * Reads the columns Laravel Fortify defines and laranail/authkit writes:
 * `two_factor_confirmed_at` is set when enrolment is confirmed, and
 * `two_factor_recovery_codes` holds the encrypted codes (null once the last
 * one is used). Neither value is decrypted here.
 */
final class FortifyTwoFactorStatus implements TwoFactorStatus
{
    public const string CONFIRMED_AT = 'two_factor_confirmed_at';

    public const string RECOVERY_CODES = 'two_factor_recovery_codes';

    public function hasConfirmedTwoFactor(Authenticatable $user): bool
    {
        $value = $this->attribute($user, self::CONFIRMED_AT);

        return $value !== null && $value !== '';
    }

    public function hasRecoveryCodes(Authenticatable $user): bool
    {
        $value = $this->attribute($user, self::RECOVERY_CODES);

        return is_string($value) && $value !== '';
    }

    private function attribute(Authenticatable $user, string $key): mixed
    {
        return method_exists($user, 'getAttribute') ? $user->getAttribute($key) : null;
    }
}
