<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Simtabi\Laranail\DBConsole\Access\Contracts\AccessManager;

/**
 * The real access resolver (replaces DenyAllAccessManager once RBAC is
 * configured). The verdict logic lives here, ONE place, so both RBAC drivers
 * produce identical allow/deny decisions (section 17, section 20):
 *
 *   gather the operator's (role, permissions, scope) assignments
 *   keep those whose role includes the requested permission
 *   keep those whose scope covers the target scope
 *   any match → allow ; else → deny (fail closed)
 *
 * A privilege-escalation is structurally impossible: an operator can only be
 * allowed a (permission, scope) that some assignment they already hold both
 * grants and covers — they can never widen their own scope or add a
 * permission their roles don't include.
 */
final readonly class RbacAccessManager implements AccessManager
{
    public function __construct(private RbacDriver $driver) {}

    public function allows(?Authenticatable $user, ConsolePermission $permission, ?string $scope): bool
    {
        if (! $user instanceof Authenticatable) {
            return false;   // deny-by-default: no operator, no access
        }

        $target = Scope::parse($scope);

        return array_any($this->driver->assignmentsFor($user), fn (ResolvedAssignment $assignment): bool => $assignment->grants($permission) && $assignment->scope->covers($target));
    }
}
