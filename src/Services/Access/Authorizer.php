<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Access;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Exceptions\NotAuthorized;
use Simtabi\Laranail\DBConsole\Events\AuthorizationDenied;
use Simtabi\Laranail\DBConsole\Security\TwoFactorEnforcer;
use Simtabi\Laranail\DBConsole\Exceptions\TwoFactorRequired;

/**
 * The single authorization entry point every service method calls. It
 * delegates to Laravel's Gate against the DBConsole ability
 * (laranail-db-console.<permission>) at a scope, and translates a denial into the
 * package's NotAuthorized exception. Every denial is also dispatched as an
 * AuthorizationDenied event, so "who tried to do what without access" is
 * audited alongside what succeeded (section 20).
 *
 * An allowed operator must also satisfy two-factor enforcement
 * (security.two_factor.enforce), so the gate decides *may they*, and the
 * enforcer decides *have they proven who they are*.
 */
final readonly class Authorizer
{
    public function __construct(
        private Gate $gate,
        private Dispatcher $events,
        private TwoFactorEnforcer $twoFactor,
        private AuthFactory $auth,
    ) {}

    /**
     * Authorize a permission at an optional scope, throwing NotAuthorized on
     * denial. The scope is a string like 'server:prod-mysql' or
     * 'database:prod-mysql/shop_prod' (null = no specific scope).
     *
     * @throws NotAuthorized when the gate denies
     * @throws TwoFactorRequired when the gate allows an operator two-factor enforcement refuses
     */
    public function authorize(ConsolePermission $permission, ?string $scope = null): void
    {
        if ($this->gate->allows($permission->ability(), $scope)) {
            $this->twoFactor->enforce($this->auth->guard()->user(), $permission->ability(), $permission->isRead());

            return;
        }

        $this->events->dispatch(new AuthorizationDenied(
            server: $this->serverFromScope($scope),
            ability: $permission->ability(),
            context: ['scope' => $scope],
        ));

        throw NotAuthorized::forAbility($permission->ability(), $scope);
    }

    private function serverFromScope(?string $scope): string
    {
        if ($scope === null) {
            return 'global';
        }

        if (str_starts_with($scope, 'server:')) {
            return substr($scope, strlen('server:'));
        }

        if (str_starts_with($scope, 'database:')) {
            $target = substr($scope, strlen('database:'));

            return explode('/', $target)[0];
        }

        return $scope;
    }
}
