<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\Contracts\AccessManager;

/**
 * Dry-run a permission at a scope for an operator (allowed/denied + why).
 */
final class AccessCheckCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.access:check {--user=} {--permission=} {--scope=global}';

    protected $description = 'Dry-run a permission at a scope for an operator';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:access:check'];

    public function handle(AccessManager $access): int
    {
        $permission = ConsolePermission::tryFrom($this->opt('permission'));
        if ($permission === null) {
            $this->failure('Unknown --permission. Expected a ConsolePermission value like database.create.');

            return self::FAILURE;
        }

        $user = $this->resolveUser($this->opt('user'));
        $scope = $this->opt('scope');

        $allowed = $access->allows($user, $permission, $scope);

        if ($allowed) {
            $this->components->info("ALLOWED: {$permission->ability()} @ {$scope}");
        } else {
            $this->components->error("DENIED: {$permission->ability()} @ {$scope} — no assignment grants this permission at a covering scope.");
        }

        return self::SUCCESS;
    }

    private function resolveUser(string $id): ?Authenticatable
    {
        /** @var class-string<Model> $model */
        $model = (string) $this->config()->get('laranail.db-console.rbac.user_model', '\App\Models\User');
        if ($id === '' || ! class_exists($model)) {
            return null;
        }

        $user = $model::query()->find($id);

        return $user instanceof Authenticatable ? $user : null;
    }
}
