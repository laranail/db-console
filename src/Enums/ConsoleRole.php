<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

/**
 * The shipped CONSOLE roles — starting points seeded on install, editable
 * afterwards. Custom roles are catalog rows, not cases here. These are
 * roles for people using the tool; they are unrelated to the privilege
 * presets granted to database users.
 */
enum ConsoleRole: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Owner'), Description('Everything, including secrets and settings management.')]
    case Owner = 'owner';

    #[Label('Admin'), Description('All database, account, and grant operations on permitted scopes; no secrets or settings management.')]
    case Admin = 'admin';

    #[Label('Operator'), Description('Create, grant, attach, and rotate; no drops, no revokes, no secrets.')]
    case Operator = 'operator';

    #[Label('Read only'), Description('Views and the audit log; no mutations.')]
    case ReadOnly = 'read_only';

    #[Label('Auditor'), Description('Audit log and dashboard only.')]
    case Auditor = 'auditor';

    /**
     * The permission composition seeded for this role.
     *
     * @return list<ConsolePermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => ConsolePermission::cases(),
            self::Admin => array_values(array_filter(
                ConsolePermission::cases(),
                static fn (ConsolePermission $p): bool => ! in_array(
                    $p,
                    [ConsolePermission::SecretsManage, ConsolePermission::SettingsManage],
                    true,
                ),
            )),
            self::Operator => [
                ConsolePermission::Access,
                ConsolePermission::DashboardView,
                ConsolePermission::ServerView,
                ConsolePermission::DatabaseView,
                ConsolePermission::DatabaseCreate,
                ConsolePermission::AccountView,
                ConsolePermission::AccountCreate,
                ConsolePermission::AccountRotate,
                ConsolePermission::GrantCreate,
                ConsolePermission::Attach,
            ],
            self::ReadOnly => [
                ConsolePermission::Access,
                ConsolePermission::DashboardView,
                ConsolePermission::AuditView,
                ConsolePermission::ServerView,
                ConsolePermission::DatabaseView,
                ConsolePermission::AccountView,
            ],
            self::Auditor => [
                ConsolePermission::Access,
                ConsolePermission::DashboardView,
                ConsolePermission::AuditView,
            ],
        };
    }
}
