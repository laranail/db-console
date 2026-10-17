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
use Simtabi\Laranail\DBConsole\Enums\TwoFactorEnforcement;
use Simtabi\Laranail\DBConsole\Exceptions\OwnerWouldBeStranded;

/**
 * Never strands the last Owner. Two changes are refused:
 *
 *   - revoking global Owner from the last operator who holds it, or (with
 *     enforcement on) from the last one with confirmed two-factor;
 *   - an enrolled global Owner losing two-factor while no other global Owner
 *     has it, with enforcement on.
 *
 * Either would leave nobody able to administer db-console under `require`.
 * Owners are read from role_assignments, which both RBAC drivers share.
 */
final readonly class OwnerSafety
{
    public function __construct(
        private SecurityPolicy $policy,
        private TwoFactorEnforcer $twoFactor,
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

        if ($this->twoFactorMatters() && $this->twoFactor->isEnrolled($assignee) && ! $this->anyEnrolled($others)) {
            throw OwnerWouldBeStranded::lastEnrolledOwner(OperatorIdentity::label($assignee), 'revoking Owner');
        }
    }

    /**
     * @throws OwnerWouldBeStranded
     */
    public function assertMayLoseTwoFactor(Authenticatable $user): void
    {
        if (! $this->twoFactorMatters() || ! $this->isGlobalOwner($user)) {
            return;
        }

        if (! $this->anyEnrolled($this->globalOwnersExcept($user))) {
            throw OwnerWouldBeStranded::lastEnrolledOwner(OperatorIdentity::label($user), 'disabling two-factor');
        }
    }

    /**
     * Global Owners who currently count as enrolled.
     */
    public function enrolledOwnerCount(): int
    {
        return count(array_filter($this->globalOwners(), $this->twoFactor->isEnrolled(...)));
    }

    public function ownerCount(): int
    {
        return count($this->globalOwners());
    }

    private function twoFactorMatters(): bool
    {
        return $this->policy->enforcement() !== TwoFactorEnforcement::Off;
    }

    private function isGlobalOwner(Authenticatable $user): bool
    {
        return $this->ownerRows()->where(OperatorIdentity::key($user))->exists();
    }

    /**
     * @param list<Authenticatable> $owners
     */
    private function anyEnrolled(array $owners): bool
    {
        return array_any($owners, $this->twoFactor->isEnrolled(...));
    }

    /**
     * @return list<Authenticatable>
     */
    private function globalOwnersExcept(Authenticatable $user): array
    {
        $key = OperatorIdentity::key($user);

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
