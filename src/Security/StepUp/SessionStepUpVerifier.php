<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security\StepUp;

use Illuminate\Http\Request;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Security\StepUpProof;
use Simtabi\Laranail\DBConsole\Security\SecurityPolicy;
use Simtabi\Laranail\DBConsole\Security\Contracts\StepUpVerifier;

/**
 * Laravel's `password.confirm` timestamp (`auth.password_confirmed_at` in the
 * session), no older than `security.step_up.ttl` seconds. laranail/authkit-preset
 * sets it from a password or a passkey, so passkeys reach db-console here with
 * no WebAuthn code of its own.
 *
 * A caller that cannot carry a session (an API token, a CLI run) is asked for
 * a one-time code instead, through the TOTP verifier.
 */
final readonly class SessionStepUpVerifier implements StepUpVerifier
{
    public const string SESSION_KEY = 'auth.password_confirmed_at';

    public function __construct(
        private Container $container,
        private SecurityPolicy $policy,
        private TotpStepUpVerifier $totp,
    ) {}

    public function verified(Authenticatable $user, StepUpProof $proof): bool
    {
        $request = $this->container->bound('request') ? $this->container->make('request') : null;
        if (! $request instanceof Request || ! $request->hasSession()) {
            return $this->totp->verified($user, $proof);
        }

        $confirmedAt = $request->session()->get(self::SESSION_KEY);
        if (! is_numeric($confirmedAt)) {
            return false;
        }

        $age = time() - (int) $confirmedAt;

        return $age >= 0 && $age <= $this->policy->stepUpTtl();
    }
}
