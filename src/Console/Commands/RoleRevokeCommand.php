<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;

/**
 * Remove a role assignment.
 */
final class RoleRevokeCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.role:revoke {--user=} {--role=} {--scope=global}';

    protected $description = 'Remove a role assignment';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:role:revoke'];

    public function handle(RbacDriver $driver): int
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
