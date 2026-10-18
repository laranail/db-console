<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Access;

use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;

/**
 * The install step that seeds the shipped console roles, gated by
 * rbac.seed_default_roles (default true). A host that manages its own roles
 * sets it false and install leaves the role tables as they are.
 */
final readonly class DefaultRoleSeeder
{
    public function __construct(
        private RbacDriver $rbac,
        private Config $config,
    ) {}

    /**
     * Seed the shipped roles when rbac.seed_default_roles is true, and say
     * whether it did.
     */
    public function seedIfEnabled(): bool
    {
        if (! (bool) $this->config->get('laranail.db-console.rbac.seed_default_roles', true)) {
            return false;
        }

        $this->rbac->seedDefaultRoles();

        return true;
    }
}
