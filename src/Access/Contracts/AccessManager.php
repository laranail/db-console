<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Access\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;

/**
 * The gate-resolution seam. The DBConsolePolicy registers one gate ability
 * per ConsolePermission and delegates the verdict here. The shipped default
 * denies everyone (DenyAllAccessManager) until roles are assigned; A7 binds
 * the real scope-aware RBAC resolver (builtin or Spatie) behind this same
 * interface, so no enforcement code changes when RBAC lands.
 */
interface AccessManager
{
    /**
     * Whether the operator may perform the permission at the given scope.
     * Deny-by-default: no assignment covering the scope → false.
     */
    public function allows(?Authenticatable $user, ConsolePermission $permission, ?string $scope): bool;
}
