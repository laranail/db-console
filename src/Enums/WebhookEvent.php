<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

/**
 * The event types a webhook subscription can listen to — the subset of
 * DBConsole's domain events deliverable to external systems. The case value
 * is the X-DBConsole-Event header value.
 */
enum WebhookEvent: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Server registered')]
    case ServerRegistered = 'server.registered';

    #[Label('Server switched')]
    case ServerSwitched = 'server.switched';

    #[Label('Database created')]
    case DatabaseCreated = 'database.created';

    #[Label('Database dropped')]
    case DatabaseDropped = 'database.dropped';

    #[Label('Database backed up')]
    case DatabaseBackedUp = 'database.backed_up';

    #[Label('Account created')]
    case AccountCreated = 'account.created';

    #[Label('Account dropped')]
    case AccountDropped = 'account.dropped';

    #[Label('Account password rotated')]
    case AccountPasswordRotated = 'account.password_rotated';

    #[Label('Account host changed')]
    case AccountHostChanged = 'account.host_changed';

    #[Label('Privileges granted')]
    case PrivilegesGranted = 'privileges.granted';

    #[Label('Privileges revoked')]
    case PrivilegesRevoked = 'privileges.revoked';

    #[Label('Databases attached')]
    case DatabasesAttached = 'databases.attached';

    #[Label('Databases detached')]
    case DatabasesDetached = 'databases.detached';

    #[Label('Secrets rotated')]
    case SecretsRotated = 'secrets.rotated';

    #[Label('Operation failed')]
    case OperationFailed = 'operation.failed';

    #[Label('Rollback performed')]
    case RollbackPerformed = 'rollback.performed';

    #[Label('Rollback failed')]
    case RollbackFailed = 'rollback.failed';

    #[Label('Suspicious activity')]
    case SuspiciousActivity = 'suspicious.activity';

    #[Label('Reconcile drift found')]
    case ReconcileDriftFound = 'reconcile.drift_found';
}
