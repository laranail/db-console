<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * The actions that need step-up: a fresh proof of identity on top of the
 * operator's permission. Each one destroys data, or changes a credential that
 * something else may be using.
 */
enum SensitiveAction: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Drop a database')]
    case DatabaseDrop = 'database.drop';

    #[Label('Drop an account')]
    case AccountDrop = 'account.drop';

    #[Label('Apply a password to an adopted account')]
    case AccountApplyPassword = 'account.apply_password';

    #[Label('Rotate an account password')]
    case AccountRotate = 'account.rotate';

    #[Label("Change an account's host")]
    case AccountHostChange = 'account.host_change';

    #[Label('Rotate stored secrets')]
    case SecretsRotate = 'secrets.rotate';

    #[Label('Regenerate two-factor recovery codes')]
    case RecoveryCodesRegenerate = 'two_factor.recovery_codes';
}
