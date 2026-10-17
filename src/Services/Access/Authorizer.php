<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Access;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Exceptions\NotAuthorized;
use Simtabi\Laranail\DBConsole\Events\AuthorizationDenied;
use Simtabi\Laranail\DBConsole\Security\AssuranceEnforcer;
use Simtabi\Laranail\DBConsole\Exceptions\AssuranceRequired;

/**
 * The single authorization entry point every service method calls. It
 * delegates to Laravel's Gate against the DBConsole ability
 * (laranail-db-console.<permission>) at a scope, and translates a denial into the
 * package's NotAuthorized exception. Every denial is also dispatched as an
 * AuthorizationDenied event, so "who tried to do what without access" is
 * audited alongside what succeeded (section 20).
 *
 * An allowed operator must also hold the assurance the operation requires
 * (security.assurance), as the host reports it through OperatorAssurance: the
 * gate decides *may they*, the host's answer decides *have they proven who
 * they are*. Doing both here is what covers the API, the WebUI and the CLI.
 */
final readonly class Authorizer
{
    public function __construct(
        private Gate $gate,
        private Dispatcher $events,
        private AssuranceEnforcer $assurance,
        private AuthFactory $auth,
    ) {}

    /**
     * Authorize a permission at an optional scope, throwing NotAuthorized on
     * denial. The scope is a string like 'server:prod-mysql' or
     * 'database:prod-mysql/shop_prod' (null = no specific scope).
     *
     * @throws NotAuthorized when the gate denies
     * @throws AssuranceRequired when the gate allows but the operator lacks the required assurance
     */
    public function authorize(ConsolePermission $permission, ?string $scope = null): void
    {
        if ($this->gate->allows($permission->ability(), $scope)) {
            $this->assure($permission, $scope);

            return;
        }

        $this->events->dispatch(new AuthorizationDenied(
            server: $this->serverFromScope($scope),
            ability: $permission->ability(),
            context: ['scope' => $scope],
        ));

        throw NotAuthorized::forAbility($permission->ability(), $scope);
    }

    /**
     * Only the assurance half, for a path that has no RBAC check of its own
     * (secrets rotation, which runs from the shell): an authenticated operator
     * still needs the assurance the permission requires.
     *
     * @throws AssuranceRequired
     */
    public function assure(ConsolePermission $permission, ?string $scope = null): void
    {
        $this->assurance->enforce($this->auth->guard()->user(), $permission, $this->serverFromScope($scope));
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
