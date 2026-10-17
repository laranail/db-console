<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

use Throwable;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DBConsole\Enums\StepUpDriver;
use Simtabi\Laranail\DBConsole\Security\OwnerSafety;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Enums\TwoFactorDriver;
use Simtabi\Laranail\DBConsole\Security\SecurityPolicy;
use Simtabi\Laranail\DBConsole\Enums\TwoFactorEnforcement;
use Simtabi\Laranail\DBConsole\Security\StepUp\TotpStepUpVerifier;
use Simtabi\Laranail\DBConsole\Security\TwoFactor\FortifyTwoFactorStatus;

/**
 * Operator-security findings: two-factor enforcement (warned whenever it is
 * off), a configuration that would lock everyone out, and step-up. Each one
 * names the key that fixes it.
 */
final readonly class OperatorSecurityCheck
{
    public function __construct(
        private SecurityPolicy $policy,
        private OwnerSafety $owners,
        private Config $config,
        private TotpStepUpVerifier $authkit,
    ) {}

    /**
     * @return list<DoctorFinding>
     */
    public function findings(): array
    {
        return [...$this->twoFactor(), $this->stepUp()];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function twoFactor(): array
    {
        $mode = $this->policy->enforcement();
        $source = $this->policy->enforcementIsDefault() ? ' (the default for this environment)' : '';

        if ($mode === TwoFactorEnforcement::Off) {
            return [DoctorFinding::warning(
                'security:two-factor',
                'Two-factor enforcement is off: an operator with only a password can drop databases and rotate credentials.',
                'Set DB_CONSOLE_2FA_ENFORCE=report to see what would be refused, then require.',
            )];
        }

        $findings = [];
        if ($mode === TwoFactorEnforcement::Report) {
            $findings[] = $this->policy->isProduction()
                ? DoctorFinding::warning('security:two-factor', "Two-factor enforcement is report{$source}: nothing is refused in production.", 'Set DB_CONSOLE_2FA_ENFORCE=require once the would-refuse alerts stop.')
                : DoctorFinding::ok('security:two-factor', "Two-factor enforcement is report{$source}: refusals are logged and alerted, not applied.");
        } else {
            $findings[] = DoctorFinding::ok('security:two-factor', "Two-factor enforcement is require{$source}, with a {$this->policy->graceDays()}-day read-only enrolment window.");
        }

        $lockout = $mode === TwoFactorEnforcement::Require;

        if ($this->policy->twoFactorDriver() === TwoFactorDriver::Null) {
            $findings[] = $lockout
                ? DoctorFinding::error('security:two-factor-driver', 'The two-factor driver is null, so no operator can ever count as enrolled: every operator is refused once the grace window ends.', 'Use DB_CONSOLE_2FA_DRIVER=fortify with laranail/authkit, or set enforce to report.')
                : DoctorFinding::warning('security:two-factor-driver', 'The two-factor driver is null, so no operator counts as enrolled.');

            return $findings;
        }

        $missing = $this->missingFortifyColumns();
        if ($missing !== []) {
            $columns = implode(', ', $missing);
            $findings[] = $lockout
                ? DoctorFinding::error('security:two-factor-driver', "The operator model has no {$columns} column, so no operator can count as enrolled.", 'Install laranail/authkit (or Laravel Fortify) and run its migrations.')
                : DoctorFinding::warning('security:two-factor-driver', "The operator model has no {$columns} column.");
        }

        $owners = $this->owners->ownerCount();
        $enrolled = $this->owners->enrolledOwnerCount();
        if ($owners > 0 && $enrolled === 0) {
            $findings[] = DoctorFinding::warning(
                'security:owners',
                "None of the {$owners} global Owner(s) has confirmed two-factor with recovery codes" . ($lockout ? ': after the grace window nobody can administer db-console.' : '.'),
                'Have an Owner enrol now. If one is locked out, run laranail::db-console.security.recover <user> from the server shell.',
            );
        }

        return $findings;
    }

    private function stepUp(): DoctorFinding
    {
        $driver = $this->policy->stepUpDriver();

        if ($driver === StepUpDriver::None) {
            return $this->policy->isProduction()
                ? DoctorFinding::error('security:step-up', 'Step-up is off (driver none) in production: drops and credential changes need no fresh proof of identity.', 'Set DB_CONSOLE_STEP_UP=session (or totp).')
                : DoctorFinding::warning('security:step-up', 'Step-up is off (driver none).');
        }

        if (! $this->authkit->available()) {
            return $driver === StepUpDriver::Totp
                ? DoctorFinding::error('security:step-up', 'Step-up is totp but laranail/authkit is not installed, so no code can verify and every dangerous action is refused.', 'composer require laranail/authkit, or use the session driver.')
                : DoctorFinding::warning('security:step-up', 'Step-up is session; without laranail/authkit, API tokens and CLI operators cannot present a one-time code, so their dangerous actions are refused.');
        }

        return DoctorFinding::ok('security:step-up', "Step-up driver: {$driver->value} (ttl {$this->policy->stepUpTtl()}s).");
    }

    /**
     * @return list<string>
     */
    private function missingFortifyColumns(): array
    {
        $class = (string) $this->config->get('laranail.db-console.rbac.user_model', '');
        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return [];
        }

        try {
            $model = new $class;
            $schema = $model->getConnection()->getSchemaBuilder();

            return array_values(array_filter(
                [FortifyTwoFactorStatus::CONFIRMED_AT, FortifyTwoFactorStatus::RECOVERY_CODES],
                static fn (string $column): bool => ! $schema->hasColumn($model->getTable(), $column),
            ));
        } catch (Throwable) {
            return [];
        }
    }
}
