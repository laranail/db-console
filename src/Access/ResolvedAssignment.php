<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Access;

use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;

/**
 * One resolved (role, scope) assignment for an operator: the role's name,
 * the permissions it grants, and the scope it applies at. An RbacDriver
 * produces these; the AccessManager checks them against a requested
 * (permission, target-scope). Because both drivers emit the same shape, the
 * builtin and Spatie drivers produce identical verdicts for identical
 * assignments.
 */
final readonly class ResolvedAssignment
{
    /**
     * @param list<ConsolePermission> $permissions
     */
    public function __construct(
        public string $role,
        public array $permissions,
        public Scope $scope,
    ) {}

    public function grants(ConsolePermission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
