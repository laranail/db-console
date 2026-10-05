<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\Contracts\AccessManager;

/**
 * Registers one gate ability per ConsolePermission (laranail-db-console.<permission>),
 * each delegating to the AccessManager for a scope-aware verdict. Wiring the
 * gate here means the API, CLI, and web UI all enforce identically through
 * Gate::allows/authorize — the single enforcement surface (section 17).
 *
 * The scope is passed as the gate's second argument (a string like
 * 'server:prod-mysql'); the AccessManager resolves coverage.
 *
 * The bare db-console.<permission> abilities used until 0.1 stay defined as
 * deprecated aliases: each announces itself once and asks the gate for the
 * scoped ability, so a host's own definition or before/after callback for the
 * scoped name governs the bare one too. Removed no earlier than the next minor
 * after 0.1.
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

            $gate->define(
                $permission->deprecatedAbility(),
                static function (?object $user, ?string $scope = null) use ($gate, $permission): bool {
                    DeprecatedAbilities::announce($permission->deprecatedAbility(), $permission->ability());

                    return $gate->forUser($user)->check($permission->ability(), $scope === null ? [] : [$scope]);
                },
            );
        }
    }
}
