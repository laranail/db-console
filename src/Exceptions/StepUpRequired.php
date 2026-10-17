<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\StepUpDriver;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;
use Simtabi\Laranail\DBConsole\Enums\SensitiveAction;

/**
 * Refused because a dangerous action needs a fresh proof of identity and none
 * was presented, or the one presented did not verify.
 */
final class StepUpRequired extends AuthorizationException
{
    public static function for(SensitiveAction $action, StepUpDriver $driver): self
    {
        $how = match ($driver) {
            StepUpDriver::Session => 'confirm your password or passkey (password.confirm), then retry',
            StepUpDriver::Totp    => 'send a current authenticator or recovery code in the X-DB-Console-OTP header, or pass --otp',
            StepUpDriver::None    => 'step-up is disabled',
        };

        return new self(
            message: "step-up required for '{$action->value}' ({$driver->value})",
            userParams: ['action' => $action->label(), 'how' => $how],
            context: ['action' => $action->value, 'driver' => $driver->value],
        );
    }

    /**
     * An HTTP request with no authenticated operator has nobody to step up.
     */
    public static function noOperator(SensitiveAction $action): self
    {
        return new self(
            message: "step-up required for '{$action->value}' and no operator is signed in",
            userParams: ['action' => $action->label(), 'how' => 'sign in first'],
            context: ['action' => $action->value, 'driver' => null],
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::StepUpRequired;
    }
}
