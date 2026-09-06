<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Models\Role;
use Simtabi\Laranail\DBConsole\Models\Permission;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;

/**
 * Create a custom console role (builtin driver).
 */
final class RoleCreateCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.role:create {--name=} {--permissions=*}';

    protected $description = 'Create a custom console role';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:role:create'];

    public function handle(): int
    {
        $name = $this->opt('name');
        if ($name === '') {
            $this->failure('A role --name is required.');

            return self::FAILURE;
        }

        /** @var list<string> $permissionValues */
        $permissionValues = (array) $this->option('permissions');
        $valid = [];
        foreach ($permissionValues as $value) {
            $permission = ConsolePermission::tryFrom($value);
            if ($permission === null) {
                $this->failure("Unknown permission '{$value}'.");

                return self::FAILURE;
            }
            $valid[] = $permission->ability();
        }

        $role = Role::query()->updateOrCreate(['name' => $name], ['is_shipped' => false]);
        $ids = Permission::query()->whereIn('name', $valid)->pluck('id');
        $role->permissions()->sync($ids);

        $this->components->info("Role '{$name}' created with " . count($valid) . ' permission(s).');

        return self::SUCCESS;
    }
}
