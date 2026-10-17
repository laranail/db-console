<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Security\BreakGlass;

/**
 * Break-glass: lift two-factor for one operator for a short window (15 minutes
 * by default), from the host's shell only. Shell access is already the trust
 * boundary, since whoever has it can read .env. Refused when invoked from an
 * HTTP request (Artisan::call in a controller), and every issue raises a
 * critical security alert.
 */
final class SecurityRecoverCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.security.recover {user : The operator id (rbac.user_model)}';

    protected $description = 'Break-glass: a one-time, short-lived two-factor bypass for one operator (shell only)';

    public function handle(BreakGlass $breakGlass): int
    {
        if (! $this->laravel->runningInConsole()) {
            $this->failure('security.recover runs only from the host shell, never over HTTP.');

            return self::FAILURE;
        }

        $id = $this->arg('user');
        $user = $this->resolveUser($id);
        if (! $user instanceof Authenticatable) {
            $this->failure("Could not resolve user '{$id}'.");

            return self::FAILURE;
        }

        $bypass = $breakGlass->issue($user, $this->shellUser());

        $this->success("Two-factor bypass issued for user {$id}, valid until {$bypass->expires_at->toAtomString()}.");
        $this->line('It covers two-factor enforcement and one step-up. The security recipients have been alerted.');
        $this->line('Enrol in two-factor and save the recovery codes before it expires.');

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

    private function shellUser(): ?string
    {
        $name = get_current_user();

        return $name !== '' ? $name : null;
    }
}
