<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use Simtabi\Laranail\DBConsole\Enums\Assurance;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Enums\AssuranceEnforcement;

/**
 * The `security.*` configuration, read in one place: how assurance is
 * enforced, and which assurance each operation requires on top of its RBAC
 * permission. The enforcer, the break-glass command and doctor all read it
 * here, so they cannot disagree.
 */
final readonly class SecurityPolicy
{
    /** `security.assurance.second_factor`: which permissions need a second factor. */
    public const string SECOND_FACTOR_ALL = 'all';

    public const string SECOND_FACTOR_WRITES = 'writes';

    public const string SECOND_FACTOR_NONE = 'none';

    private const string PREFIX = 'laranail.db-console.security.';

    public function __construct(
        private Config $config,
        private Application $app,
    ) {}

    public function isProduction(): bool
    {
        return $this->app->environment('production');
    }

    public function enforcement(): AssuranceEnforcement
    {
        return AssuranceEnforcement::resolve($this->config->get(self::PREFIX . 'assurance.enforce'), $this->isProduction());
    }

    /**
     * Whether `enforce` was left unset, so the environment chose the mode.
     */
    public function enforcementIsDefault(): bool
    {
        $value = $this->config->get(self::PREFIX . 'assurance.enforce');

        return ! is_string($value) || trim($value) === '';
    }

    /**
     * What a permission requires, beyond the permission itself.
     *
     * @return list<Assurance>
     */
    public function requiredFor(ConsolePermission $permission): array
    {
        $required = [];

        if ($this->needsSecondFactor($permission)) {
            $required[] = Assurance::SecondFactor;
        }

        if (in_array($permission, $this->recentConfirmationPermissions(), true)) {
            $required[] = Assurance::RecentConfirmation;
        }

        return $required;
    }

    /**
     * `all`, `writes` (default) or `none`. An unrecognised value resolves to
     * `all`: a typo must not switch the requirement off.
     */
    public function secondFactorScope(): string
    {
        $value = strtolower(trim((string) $this->config->get(self::PREFIX . 'assurance.second_factor', self::SECOND_FACTOR_WRITES)));

        return in_array($value, [self::SECOND_FACTOR_ALL, self::SECOND_FACTOR_WRITES, self::SECOND_FACTOR_NONE], true)
            ? $value
            : self::SECOND_FACTOR_ALL;
    }

    /**
     * The permissions that need a confirmation within the step-up ttl.
     *
     * @return list<ConsolePermission>
     */
    public function recentConfirmationPermissions(): array
    {
        $configured = (array) $this->config->get(self::PREFIX . 'assurance.recent_confirmation', []);

        $permissions = [];
        foreach ($configured as $value) {
            $permission = is_string($value) ? ConsolePermission::fromAbility($value) : null;
            if ($permission instanceof ConsolePermission) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    public function stepUpTtl(): int
    {
        return max(1, (int) $this->config->get(self::PREFIX . 'step_up.ttl', 300));
    }

    public function breakGlassMinutes(): int
    {
        return max(1, (int) $this->config->get(self::PREFIX . 'break_glass_minutes', 15));
    }

    private function needsSecondFactor(ConsolePermission $permission): bool
    {
        return match ($this->secondFactorScope()) {
            self::SECOND_FACTOR_NONE   => false,
            self::SECOND_FACTOR_WRITES => ! $permission->isRead(),
            default                    => true,
        };
    }
}
