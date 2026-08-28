<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Access\Drivers;

use Illuminate\Support\Collection;
use Simtabi\Laranail\DBConsole\Access\Scope;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\ScopeType;
use Spatie\Permission\Models\Role as SpatieRole;
use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Models\RoleAssignment;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\ResolvedAssignment;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Simtabi\Laranail\DBConsole\Exceptions\ServerMisconfigured;
use Simtabi\Laranail\DBConsole\Enums\RbacDriver as RbacDriverEnum;

/**
 * RBAC that delegates role→permission storage to spatie/laravel-permission,
 * for teams already on Spatie. The (assignee, role, scope) triple still lives
 * in DBConsole's role_assignments table (Spatie has no scope model), so the
 * resolution logic — and therefore the verdicts — are identical to the
 * builtin driver for the same assignments (section 17).
 *
 * Requires spatie/laravel-permission; absent, it reports misconfiguration.
 */
final class SpatieRbacDriver implements RbacDriver
{
    public function __construct()
    {
        if (! class_exists(SpatieRole::class)) {
            throw ServerMisconfigured::named(
                'rbac',
                'the spatie RBAC driver needs spatie/laravel-permission (composer require spatie/laravel-permission)',
            );
        }
    }

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
                scope: Scope::parse($row->scopeString()),
            );
        }

        return $assignments;
    }

    public function assign(Authenticatable $assignee, string $role, string $scope): void
    {
        $parsed = Scope::parse($scope);

        RoleAssignment::query()->updateOrCreate([
            'assignee_type' => $assignee::class,
            'assignee_id'   => (string) $assignee->getAuthIdentifier(),
            'role'          => $role,
            'scope_type'    => $parsed->type->value,
            'scope_ref'     => $this->scopeRef($parsed),
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
            SpatiePermission::findOrCreate($permission->ability());
        }

        foreach (ConsoleRole::cases() as $consoleRole) {
            $role = SpatieRole::findOrCreate($consoleRole->value);
            $role->syncPermissions(array_map(
                static fn (ConsolePermission $p): string => $p->ability(),
                $consoleRole->permissions(),
            ));
        }
    }

    public function assignBootstrapOwner(Authenticatable $owner): void
    {
        $this->assign($owner, ConsoleRole::Owner->value, 'global');
    }

    public function permissionsForRole(string $role): array
    {
        $spatieRole = SpatieRole::query()->where('name', $role)->first();
        if ($spatieRole === null) {
            $shipped = ConsoleRole::tryFrom($role);

            return $shipped instanceof ConsoleRole ? $shipped->permissions() : [];
        }

        $permissions = [];
        /** @var iterable<int, object{name: string}> $rolePermissions */
        $rolePermissions = $spatieRole->permissions;
        foreach ($rolePermissions as $permission) {
            $resolved = $this->permissionFromAbility((string) $permission->name);
            if ($resolved instanceof ConsolePermission) {
                $permissions[] = $resolved;
            }
        }

        return $permissions;
    }

    public function driver(): string
    {
        return RbacDriverEnum::Spatie->value;
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

    private function scopeRef(Scope $scope): ?string
    {
        return match ($scope->type) {
            ScopeType::Global   => null,
            ScopeType::Server   => $scope->server,
            ScopeType::Database => $scope->server . '/' . $scope->databasePattern,
        };
    }

    private function permissionFromAbility(string $ability): ?ConsolePermission
    {
        $value = str_starts_with($ability, 'db-console.') ? substr($ability, strlen('db-console.')) : $ability;

        return ConsolePermission::tryFrom($value);
    }
}
