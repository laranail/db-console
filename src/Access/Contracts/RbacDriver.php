<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Access\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\ResolvedAssignment;

/**
 * The RBAC storage seam. Two implementations behind one contract
 * (BuiltinRbacDriver, SpatieRbacDriver); the AccessManager and ScopeResolver
 * above are identical for both, so the same assignments yield the same
 * verdicts regardless of backend (section 17).
 *
 * The (assignee, role, scope) triple — including scope — is DBConsole's own
 * concept in both modes (Spatie has no scope model); only the role→permission
 * composition differs (our tables vs Spatie's).
 */
interface RbacDriver
{
    /**
     * The operator's resolved (role, permissions, scope) assignments,
     * including any inherited via teams (v-next). Empty for an operator with
     * no assignments — deny-by-default.
     *
     * @return list<ResolvedAssignment>
     */
    public function assignmentsFor(?Authenticatable $user): array;

    /**
     * Assign a role to an assignee at a scope (the wire scope string).
     */
    public function assign(Authenticatable $assignee, string $role, string $scope): void;

    /**
     * Remove a role assignment at a scope.
     */
    public function revoke(Authenticatable $assignee, string $role, string $scope): void;

    /**
     * Seed the shipped roles (Owner/Admin/Operator/ReadOnly/Auditor) with
     * their permission composition. Idempotent.
     */
    public function seedDefaultRoles(): void;

    /**
     * Assign a shipped role to the bootstrap operator at global scope (the
     * install step that seeds the RBAC tree, scenario A).
     */
    public function assignBootstrapOwner(Authenticatable $owner): void;

    /**
     * The permissions a named role grants (shipped or custom).
     *
     * @return list<ConsolePermission>
     */
    public function permissionsForRole(string $role): array;

    public function driver(): string;
}
