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
 * Gate abilities are the prefixed form from ability(): `laranail-db-console.<permission>`.
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
     * The prefix every gate ability carries.
     */
    public const string ABILITY_PREFIX = 'laranail-db-console.';

    /**
     * The bare prefix the gate abilities carried until 0.1. Abilities under it are still defined,
     * as deprecated aliases that delegate to the scoped ones, and are removed no earlier than the
     * next minor after 0.1. Permission names stored under it still resolve (see fromAbility()).
     */
    public const string DEPRECATED_ABILITY_PREFIX = 'db-console.';

    /**
     * The permission a gate ability or stored permission name stands for, in either form
     * (`laranail-db-console.x`, or the pre-0.1 `db-console.x`), or the bare permission value.
     * Null for anything else, including another package's ability.
     */
    public static function fromAbility(string $ability): ?self
    {
        foreach ([self::ABILITY_PREFIX, self::DEPRECATED_ABILITY_PREFIX] as $prefix) {
            if (str_starts_with($ability, $prefix)) {
                return self::tryFrom(substr($ability, strlen($prefix)));
            }
        }

        return self::tryFrom($ability);
    }

    /**
     * The gate ability string for this permission: `laranail-db-console.<permission>`.
     */
    public function ability(): string
    {
        return self::ABILITY_PREFIX . $this->value;
    }

    /**
     * The bare ability this permission was checked by until 0.1: `db-console.<permission>`.
     * Still defined on the gate as a deprecated alias of ability().
     */
    public function deprecatedAbility(): string
    {
        return self::DEPRECATED_ABILITY_PREFIX . $this->value;
    }
}
