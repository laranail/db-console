<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;

/**
 * Show what an operator can do, and where.
 */
final class AccessShowCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.access:show {--user=}';

    protected $description = 'Show an operator\'s resolved permissions per scope';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:access:show'];

    public function handle(RbacDriver $driver): int
    {
        $user = $this->resolveUser($this->opt('user'));
        if (! $user instanceof Authenticatable) {
            $this->failure('Could not resolve --user.');

            return self::FAILURE;
        }

        $assignments = $driver->assignmentsFor($user);
        if ($assignments === []) {
            $this->components->warn('No assignments — this operator is denied everywhere (deny-by-default).');

            return self::SUCCESS;
        }

        foreach ($assignments as $assignment) {
            $permissions = array_map(static fn (ConsolePermission $p): string => $p->value, $assignment->permissions);
            $this->line("<comment>{$assignment->role}</comment> @ {$assignment->scope->toString()}");
            $this->line('  ' . implode(', ', $permissions));
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
