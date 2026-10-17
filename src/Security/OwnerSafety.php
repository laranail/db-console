<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\DBConsole\Access\Scope;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\ScopeType;
use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Models\RoleAssignment;
use Simtabi\Laranail\DBConsole\Enums\AssuranceEnforcement;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;
use Simtabi\Laranail\DBConsole\Exceptions\OwnerWouldBeStranded;

/**
 * Never strands the last Owner. Refused:
 *
 *   - revoking global Owner from the last operator who holds it, or (with
 *     enforcement on) from the last one the host reports a second factor for;
 *   - assertMayLoseSecondFactor(), for the host to call before it disables an
 *     operator's second factor: refused for the last global Owner with one.
 *
 * Either would leave nobody able to administer db-console under `require`.
 * Owners are read from role_assignments, which both RBAC drivers share.
 */
final readonly class OwnerSafety
{
    public function __construct(
        private SecurityPolicy $policy,
        private OperatorAssurance $assurance,
    ) {}

    /**
     * @throws OwnerWouldBeStranded
     */
    public function assertMayRevoke(Authenticatable $assignee, string $role, string $scope): void
    {
        if ($role !== ConsoleRole::Owner->value || Scope::parse($scope)->type !== ScopeType::Global) {
            return;
        }

        if (! $this->isGlobalOwner($assignee)) {
            return;
        }

        $others = $this->globalOwnersExcept($assignee);
        if ($others === []) {
            throw OwnerWouldBeStranded::lastOwner(OperatorIdentity::label($assignee));
        }

        if ($this->enforcementOn() && $this->assurance->hasSecondFactor($assignee) && ! $this->anyWithSecondFactor($others)) {
            throw OwnerWouldBeStranded::lastEnrolledOwner(OperatorIdentity::label($assignee), 'revoking Owner');
        }
    }

    /**
     * For the host to call before disabling an operator's second factor.
     *
     * @throws OwnerWouldBeStranded
     */
    public function assertMayLoseSecondFactor(Authenticatable $operator): void
    {
        if (! $this->enforcementOn() || ! $this->isGlobalOwner($operator)) {
            return;
        }

        if (! $this->anyWithSecondFactor($this->globalOwnersExcept($operator))) {
            throw OwnerWouldBeStranded::lastEnrolledOwner(OperatorIdentity::label($operator), 'disabling the second factor');
        }
    }

    /**
     * Global Owners the host reports a second factor for.
     */
    public function ownersWithSecondFactor(): int
    {
        return count(array_filter($this->globalOwners(), $this->assurance->hasSecondFactor(...)));
    }

    public function ownerCount(): int
    {
        return count($this->globalOwners());
    }

    private function enforcementOn(): bool
    {
        return $this->policy->enforcement() !== AssuranceEnforcement::Off;
    }

    private function isGlobalOwner(Authenticatable $operator): bool
    {
        return $this->ownerRows()->where(OperatorIdentity::key($operator))->exists();
    }

    /**
     * @param list<Authenticatable> $owners
     */
    private function anyWithSecondFactor(array $owners): bool
    {
        return array_any($owners, $this->assurance->hasSecondFactor(...));
    }

    /**
     * @return list<Authenticatable>
     */
    private function globalOwnersExcept(Authenticatable $operator): array
    {
        $key = OperatorIdentity::key($operator);

        return array_values(array_filter(
            $this->globalOwners(),
            static fn (Authenticatable $owner): bool => OperatorIdentity::key($owner) !== $key,
        ));
    }

    /**
     * Every resolvable global Owner. An assignment whose operator no longer
     * exists counts for nobody.
     *
     * @return list<Authenticatable>
     */
    private function globalOwners(): array
    {
        $owners = [];
        foreach ($this->ownerRows()->get(['assignee_type', 'assignee_id']) as $row) {
            $class = $row->assignee_type;
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $owner = $class::query()->find($row->assignee_id);
            if ($owner instanceof Authenticatable) {
                $owners[] = $owner;
            }
        }

        return $owners;
    }

    /**
     * @return Builder<RoleAssignment>
     */
    private function ownerRows(): Builder
    {
        return RoleAssignment::query()
            ->where('role', ConsoleRole::Owner->value)
            ->where('scope_type', ScopeType::Global->value);
    }
}
