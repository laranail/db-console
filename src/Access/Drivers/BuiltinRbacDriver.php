<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Access\Drivers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Simtabi\Laranail\DBConsole\Access\ResolvedAssignment;
use Simtabi\Laranail\DBConsole\Access\Scope;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Enums\RbacDriver as RbacDriverEnum;
use Simtabi\Laranail\DBConsole\Enums\ScopeType;
use Simtabi\Laranail\DBConsole\Models\Permission;
use Simtabi\Laranail\DBConsole\Models\Role;
use Simtabi\Laranail\DBConsole\Models\RoleAssignment;

/**
 * RBAC backed by DBConsole's own tables (roles, permissions, pivot,
 * role_assignments). Ships with the package, no dependency. Role→permission
 * composition lives in our tables; the (assignee, role, scope) triple lives
 * in role_assignments (shared with Spatie mode).
 */
final class BuiltinRbacDriver implements RbacDriver
{
    public function assignmentsFor(?Authenticatable $user): array
    {
        if (! $user instanceof Authenticatable) {
            return [];
        }

        $assignments = [];
        foreach ($this->assignmentRows($user) as $row) {
            $assignments[] = new ResolvedAssignment(
                role: $row->role,
                permissions: $this->permissionsForRole($row->role),
                scope: $this->scopeFrom($row),
            );
        }

        return $assignments;
    }

    public function assign(Authenticatable $assignee, string $role, string $scope): void
    {
        $parsed = Scope::parse($scope);

        RoleAssignment::query()->updateOrCreate([
            'assignee_type' => $assignee::class,
            'assignee_id' => (string) $assignee->getAuthIdentifier(),
            'role' => $role,
            'scope_type' => $parsed->type->value,
            'scope_ref' => $this->scopeRef($parsed),
        ]);
    }

    public function revoke(Authenticatable $assignee, string $role, string $scope): void
    {
        $parsed = Scope::parse($scope);

        RoleAssignment::query()
            ->where('assignee_type', $assignee::class)
            ->where('assignee_id', (string) $assignee->getAuthIdentifier())
            ->where('role', $role)
            ->where('scope_type', $parsed->type->value)
            ->where('scope_ref', $this->scopeRef($parsed))
            ->delete();
    }

    public function seedDefaultRoles(): void
    {
        foreach (ConsolePermission::cases() as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission->ability()]);
        }

        foreach (ConsoleRole::cases() as $consoleRole) {
            $role = Role::query()->updateOrCreate(
                ['name' => $consoleRole->value],
                ['label' => $consoleRole->label(), 'is_shipped' => true],
            );

            $permissionIds = Permission::query()
                ->whereIn('name', array_map(
                    static fn (ConsolePermission $p): string => $p->ability(),
                    $consoleRole->permissions(),
                ))
                ->pluck('id');

            $role->permissions()->sync($permissionIds);
        }
    }

    public function assignBootstrapOwner(Authenticatable $owner): void
    {
        $this->assign($owner, ConsoleRole::Owner->value, 'global');
    }

    public function permissionsForRole(string $role): array
    {
        $shipped = ConsoleRole::tryFrom($role);
        if ($shipped instanceof ConsoleRole && ! $this->roleExists($role)) {
            return $shipped->permissions();
        }

        $names = Role::query()->where('name', $role)->first()?->permissions()->pluck('name')->all() ?? [];

        $permissions = [];
        foreach ($names as $name) {
            $permission = $this->permissionFromAbility((string) $name);
            if ($permission instanceof ConsolePermission) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    public function driver(): string
    {
        return RbacDriverEnum::Builtin->value;
    }

    /**
     * @return Collection<int, RoleAssignment>
     */
    private function assignmentRows(Authenticatable $user): Collection
    {
        return RoleAssignment::query()
            ->where('assignee_type', $user::class)
            ->where('assignee_id', (string) $user->getAuthIdentifier())
            ->get();
    }

    private function scopeFrom(RoleAssignment $row): Scope
    {
        return Scope::parse($row->scopeString());
    }

    private function scopeRef(Scope $scope): ?string
    {
        return match ($scope->type) {
            ScopeType::Global => null,
            ScopeType::Server => $scope->server,
            ScopeType::Database => $scope->server.'/'.$scope->databasePattern,
        };
    }

    private function roleExists(string $role): bool
    {
        return Role::query()->where('name', $role)->exists();
    }

    private function permissionFromAbility(string $ability): ?ConsolePermission
    {
        $value = str_starts_with($ability, 'db-console.') ? substr($ability, strlen('db-console.')) : $ability;

        return ConsolePermission::tryFrom($value);
    }
}
