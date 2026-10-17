<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

use Simtabi\Laranail\DBConsole\Security\OwnerSafety;
use Simtabi\Laranail\DBConsole\Security\SecurityPolicy;
use Simtabi\Laranail\DBConsole\Enums\AssuranceEnforcement;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;
use Simtabi\Laranail\DBConsole\Security\NullOperatorAssurance;

/**
 * Operator-assurance findings: whether enforcement is on (an error in
 * production when it is off), whether the host bound a real OperatorAssurance,
 * and whether any global Owner could still act under `require`. Each one names
 * what fixes it.
 */
final readonly class OperatorSecurityCheck
{
    public function __construct(
        private SecurityPolicy $policy,
        private OperatorAssurance $assurance,
        private OwnerSafety $owners,
    ) {}

    /**
     * @return list<DoctorFinding>
     */
    public function findings(): array
    {
        $findings = [$this->enforcement()];

        if ($this->assurance instanceof NullOperatorAssurance) {
            $findings[] = DoctorFinding::warning(
                'security:assurance-binding',
                'No OperatorAssurance is bound, so db-console sees no second factor and no recent confirmation for anyone'
                    . ($this->policy->enforcement() === AssuranceEnforcement::Require ? ': every operation that requires assurance is refused.' : '.'),
                'Bind your application\'s implementation of Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance (see the operator-assurance recipe).',
            );

            return $findings;
        }

        $findings[] = DoctorFinding::ok('security:assurance-binding', 'OperatorAssurance: ' . $this->assurance::class . '.');

        $owners = $this->owners->ownerCount();
        if ($this->policy->enforcement() !== AssuranceEnforcement::Off && $owners > 0 && $this->owners->ownersWithSecondFactor() === 0) {
            $findings[] = DoctorFinding::warning(
                'security:owners',
                "None of the {$owners} global Owner(s) has a second factor, so no Owner can make changes under require.",
                'Have an Owner enrol in your application. If one is locked out, run laranail::db-console.security.recover <user> from the server shell.',
            );
        }

        return $findings;
    }

    private function enforcement(): DoctorFinding
    {
        $mode = $this->policy->enforcement();
        $source = $this->policy->enforcementIsDefault() ? ' (the default for this environment)' : '';

        return match ($mode) {
            AssuranceEnforcement::Off => $this->policy->isProduction()
                ? DoctorFinding::error('security:assurance', 'Assurance enforcement is off in production: an operator with only a password can drop databases and rotate credentials.', 'Unset DB_CONSOLE_ASSURANCE_ENFORCE (require in production), or set it to report while you roll out.')
                : DoctorFinding::warning('security:assurance', 'Assurance enforcement is off.', 'Set DB_CONSOLE_ASSURANCE_ENFORCE=report to see what require would refuse.'),
            AssuranceEnforcement::Report => $this->policy->isProduction()
                ? DoctorFinding::warning('security:assurance', "Assurance enforcement is report{$source}: nothing is refused in production.", 'Set DB_CONSOLE_ASSURANCE_ENFORCE=require once the shortfall alerts stop.')
                : DoctorFinding::ok('security:assurance', "Assurance enforcement is report{$source}: shortfalls are audited, not refused."),
            AssuranceEnforcement::Require => DoctorFinding::ok('security:assurance', "Assurance enforcement is require{$source}."),
        };
    }
}
