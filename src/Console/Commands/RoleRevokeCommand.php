<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Security\OwnerSafety;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Simtabi\Laranail\DBConsole\Exceptions\OwnerWouldBeStranded;

/**
 * Remove a role assignment. Revoking global Owner is refused when it would
 * leave no Owner, or no Owner with confirmed two-factor while enforcement is on.
 */
final class RoleRevokeCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.role:revoke {--user=} {--role=} {--scope=global}';

    protected $description = 'Remove a role assignment';

    public function handle(RbacDriver $driver, OwnerSafety $owners): int
    {
        $userId = $this->opt('user');
        $role = $this->opt('role');
        $scope = $this->opt('scope');

        if ($userId === '' || $role === '') {
            $this->failure('--user and --role are required.');

            return self::FAILURE;
        }

        $user = $this->resolveUser($userId);
        if (! $user instanceof Authenticatable) {
            $this->failure("Could not resolve user '{$userId}'.");

            return self::FAILURE;
        }

        try {
            $owners->assertMayRevoke($user, $role, $scope);
        } catch (OwnerWouldBeStranded $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        $driver->revoke($user, $role, $scope);
        $this->components->info("Revoked '{$role}' from user {$userId} at {$scope}.");

        return self::SUCCESS;
    }

    private function resolveUser(string $id): ?Authenticatable
    {
        /** @var class-string<Model> $model */
        $model = (string) $this->config()->get('laranail.db-console.rbac.user_model', '\App\Models\User');
        if (! class_exists($model)) {
            return null;
        }

        $user = $model::query()->find($id);

        return $user instanceof Authenticatable ? $user : null;
    }
}
