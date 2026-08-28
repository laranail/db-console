<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\Contracts\AccessManager;

/**
 * Registers one gate ability per ConsolePermission (db-console.<permission>),
 * each delegating to the AccessManager for a scope-aware verdict. Wiring the
 * gate here means the API, CLI, and web UI all enforce identically through
 * Gate::allows/authorize — the single enforcement surface (section 17).
 *
 * The scope is passed as the gate's second argument (a string like
 * 'server:prod-mysql'); the AccessManager resolves coverage.
 */
final readonly class DBConsolePolicy
{
    public function __construct(private AccessManager $access) {}

    public function register(Gate $gate): void
    {
        foreach (ConsolePermission::cases() as $permission) {
            $gate->define(
                $permission->ability(),
                fn (?object $user, ?string $scope = null): bool => $this->access->allows(
                    $user instanceof Authenticatable ? $user : null,
                    $permission,
                    $scope,
                ),
            );
        }
    }
}
