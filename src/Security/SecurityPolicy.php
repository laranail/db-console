<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use Illuminate\Contracts\Foundation\Application;
use Simtabi\Laranail\DBConsole\Enums\StepUpDriver;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Enums\TwoFactorDriver;
use Simtabi\Laranail\DBConsole\Enums\TwoFactorEnforcement;

/**
 * The `security.*` configuration, read in one place so the enforcer, the
 * step-up gate, the break-glass command and doctor can never disagree about
 * what is configured.
 */
final readonly class SecurityPolicy
{
    private const string PREFIX = 'laranail.db-console.security.';

    public function __construct(
        private Config $config,
        private Application $app,
    ) {}

    public function isProduction(): bool
    {
        return $this->app->environment('production');
    }

    public function enforcement(): TwoFactorEnforcement
    {
        return TwoFactorEnforcement::resolve($this->config->get(self::PREFIX . 'two_factor.enforce'), $this->isProduction());
    }

    /**
     * Whether `enforce` was left unset, so the environment chose the mode.
     */
    public function enforcementIsDefault(): bool
    {
        $value = $this->config->get(self::PREFIX . 'two_factor.enforce');

        return ! is_string($value) || trim($value) === '';
    }

    public function twoFactorDriver(): TwoFactorDriver
    {
        return TwoFactorDriver::tryFrom((string) $this->config->get(self::PREFIX . 'two_factor.driver', 'fortify'))
            ?? TwoFactorDriver::Fortify;
    }

    public function graceDays(): int
    {
        return max(0, (int) $this->config->get(self::PREFIX . 'two_factor.grace_days', 7));
    }

    public function breakGlassMinutes(): int
    {
        return max(1, (int) $this->config->get(self::PREFIX . 'two_factor.break_glass_minutes', 15));
    }

    /**
     * The configured step-up driver. An unrecognised value resolves to
     * `session`, never to `none`: a typo must not switch step-up off.
     */
    public function stepUpDriver(): StepUpDriver
    {
        return StepUpDriver::tryFrom((string) $this->config->get(self::PREFIX . 'step_up.driver', 'session'))
            ?? StepUpDriver::Session;
    }

    public function stepUpTtl(): int
    {
        return max(1, (int) $this->config->get(self::PREFIX . 'step_up.ttl', 300));
    }
}
