<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * How an operator proves, again, that it is them before a dangerous action
 * (`security.step_up.driver`).
 */
enum StepUpDriver: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Session'), Description("Laravel's password.confirm timestamp, set by a password or (through laranail/authkit-preset) a passkey.")]
    case Session = 'session';

    #[Label('TOTP'), Description('A one-time code in the X-DB-Console-OTP header or the --otp option, verified by laranail/authkit.')]
    case Totp = 'totp';

    #[Label('None'), Description('No step-up. doctor refuses it in production.')]
    case None = 'none';
}
