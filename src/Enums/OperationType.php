<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * The privileged operations DBConsole performs — the vocabulary of the
 * audit log, the structured logger, events, and notification routing.
 */
enum OperationType: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Create database')]
    case DatabaseCreate = 'database.create';

    #[Label('Drop database')]
    case DatabaseDrop = 'database.drop';

    #[Label('Create account')]
    case AccountCreate = 'account.create';

    #[Label('Drop account')]
    case AccountDrop = 'account.drop';

    #[Label('Rotate account password')]
    case AccountRotate = 'account.rotate';

    #[Label('Change account host')]
    case AccountHostChanged = 'account.host_changed';

    #[Label('Grant privileges')]
    case GrantCreate = 'grant.create';

    #[Label('Revoke privileges')]
    case GrantRevoke = 'grant.revoke';

    #[Label('Attach users to databases')]
    case Attach = 'attach';

    #[Label('Detach users from databases')]
    case Detach = 'detach';

    #[Label('Register server')]
    case ServerRegistered = 'server.registered';

    #[Label('Switch server')]
    case ServerSwitched = 'server.switched';

    #[Label('Rotate stored secrets')]
    case SecretsRotated = 'secrets.rotated';

    /**
     * Whether this operation destroys data or access when it succeeds.
     */
    public function isDestructive(): bool
    {
        return match ($this) {
            self::DatabaseDrop, self::AccountDrop => true,
            default                               => false,
        };
    }
}
