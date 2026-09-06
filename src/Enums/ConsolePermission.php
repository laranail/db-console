<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * CONSOLE permissions: what an operator may do with the tool. Entirely
 * distinct from the MANAGED privileges DBConsole grants to database users.
 * Gate abilities are the prefixed form from ability().
 */
enum ConsolePermission: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Open the console')]
    case Access = 'access';

    #[Label('View the dashboard')]
    case DashboardView = 'dashboard.view';

    #[Label('View the audit log')]
    case AuditView = 'audit.view';

    #[Label('View servers')]
    case ServerView = 'server.view';

    #[Label('Manage servers')]
    case ServerManage = 'server.manage';

    #[Label('View databases')]
    case DatabaseView = 'database.view';

    #[Label('Create databases')]
    case DatabaseCreate = 'database.create';

    #[Label('Drop databases')]
    case DatabaseDrop = 'database.drop';

    #[Label('View accounts')]
    case AccountView = 'account.view';

    #[Label('Create accounts')]
    case AccountCreate = 'account.create';

    #[Label('Drop accounts')]
    case AccountDrop = 'account.drop';

    #[Label('Rotate account passwords')]
    case AccountRotate = 'account.rotate';

    #[Label('Edit account configuration')]
    case AccountEdit = 'account.edit';

    #[Label('Grant privileges')]
    case GrantCreate = 'grant.create';

    #[Label('Revoke privileges')]
    case GrantRevoke = 'grant.revoke';

    #[Label('Attach users to databases')]
    case Attach = 'attach';

    #[Label('Detach users from databases')]
    case Detach = 'detach';

    #[Label('Manage webhooks')]
    case WebhookManage = 'webhook.manage';

    #[Label('Manage API tokens')]
    case TokenManage = 'token.manage';

    #[Label('Manage secrets')]
    case SecretsManage = 'secrets.manage';

    #[Label('Manage settings')]
    case SettingsManage = 'settings.manage';

    /**
     * The gate ability string for this permission.
     */
    public function ability(): string
    {
        return 'db-console.' . $this->value;
    }
}
