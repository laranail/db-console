<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;

/**
 * List console roles and their permissions.
 */
final class RoleListCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.role:list';

    protected $description = 'List console roles and their permissions';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:role:list'];

    public function handle(RbacDriver $driver): int
    {
        foreach (ConsoleRole::cases() as $role) {
            $permissions = array_map(static fn (ConsolePermission $p): string => $p->value, $driver->permissionsForRole($role->value));
            $this->line("<comment>{$role->value}</comment> ({$role->label()})");
            $this->line('  ' . implode(', ', $permissions));
        }

        return self::SUCCESS;
    }
}
