<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security\StepUp;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Security\StepUpProof;
use Simtabi\Laranail\DBConsole\Security\Contracts\StepUpVerifier;

/**
 * A current authenticator code, or a single-use recovery code, verified by
 * laranail/authkit's TwoFactorAuthentication. authkit is a suggestion, not a
 * requirement: without it no code can verify, so step-up is refused (doctor
 * says why) rather than silently passed.
 */
final readonly class TotpStepUpVerifier implements StepUpVerifier
{
    public const string AUTHKIT = 'Simtabi\\Laranail\\AuthKit\\Services\\TwoFactorAuthentication';

    public function __construct(private Container $container) {}

    /**
     * authkit is installed, or the host bound its own service under authkit's name.
     */
    public function available(): bool
    {
        return class_exists(self::AUTHKIT) || $this->container->bound(self::AUTHKIT);
    }

    public function verified(Authenticatable $user, StepUpProof $proof): bool
    {
        $otp = $proof->otp();
        if ($otp === null || ! $this->available()) {
            return false;
        }

        $twoFactor = $this->container->make(self::AUTHKIT);

        return is_object($twoFactor) && method_exists($twoFactor, 'verify') && $twoFactor->verify($user, $otp) === true;
    }
}
