<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\SensitiveAction;
use Simtabi\Laranail\DBConsole\Exceptions\StepUpRequired;
use Simtabi\Laranail\DBConsole\Exceptions\ServerMisconfigured;
use Simtabi\Laranail\DBConsole\Security\StepUp\TotpStepUpVerifier;

/**
 * Regenerating two-factor recovery codes, behind step-up. The codes are
 * laranail/authkit's; db-console only puts its step-up in front, for surfaces
 * (db-console-webui) that offer regeneration themselves.
 */
final readonly class RecoveryCodes
{
    public function __construct(
        private StepUpGate $stepUp,
        private Container $container,
        private TotpStepUpVerifier $authkit,
    ) {}

    /**
     * @return list<string> the new codes, shown once
     *
     * @throws StepUpRequired
     */
    public function regenerate(Authenticatable $user): array
    {
        $this->stepUp->require(SensitiveAction::RecoveryCodesRegenerate);

        if (! $this->authkit->available()) {
            throw ServerMisconfigured::named('security', 'regenerating recovery codes needs laranail/authkit');
        }

        $twoFactor = $this->container->make(TotpStepUpVerifier::AUTHKIT);
        $codes = is_object($twoFactor) && method_exists($twoFactor, 'rotateRecoveryCodes') ? $twoFactor->rotateRecoveryCodes($user) : [];

        return array_values(array_map(strval(...), is_array($codes) ? $codes : []));
    }
}
