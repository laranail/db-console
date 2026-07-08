<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Access\Contracts\AccessManager;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;

/**
 * The shipped default until RBAC is configured: deny everyone. This is the
 * single most important control expressed as code — the tool is closed until
 * an operator is explicitly assigned a role at a scope (section 7). A7 binds
 * the real resolver in its place.
 */
final class DenyAllAccessManager implements AccessManager
{
    public function allows(?Authenticatable $user, ConsolePermission $permission, ?string $scope): bool
    {
        return false;
    }
}
