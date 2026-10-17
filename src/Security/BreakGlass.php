<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Models\SecurityBypass;
use Simtabi\Laranail\DBConsole\Events\BreakGlassIssued;

/**
 * Break-glass bypasses, issued only from the host's shell. While one is live
 * the operator counts as enrolled in two-factor, so a lost device never locks
 * the last Owner out; it may stand in for step-up exactly once. Issuing a new
 * bypass ends any earlier one, and every issue raises a critical alert.
 */
final readonly class BreakGlass
{
    public function __construct(
        private SecurityPolicy $policy,
        private Dispatcher $events,
    ) {}

    public function issue(Authenticatable $user, ?string $issuedBy): SecurityBypass
    {
        $now = CarbonImmutable::now();

        SecurityBypass::query()
            ->where(OperatorIdentity::key($user))
            ->where('expires_at', '>', $now)
            ->update(['expires_at' => $now]);

        $bypass = SecurityBypass::query()->create([
            ...OperatorIdentity::key($user),
            'issued_by'  => $issuedBy,
            'expires_at' => $now->addMinutes($this->policy->breakGlassMinutes()),
        ]);

        $this->events->dispatch(new BreakGlassIssued(
            operator: OperatorIdentity::label($user),
            expiresAt: $bypass->expires_at->toAtomString(),
            issuedBy: $issuedBy,
        ));

        return $bypass;
    }

    public function active(Authenticatable $user): ?SecurityBypass
    {
        return SecurityBypass::query()
            ->where(OperatorIdentity::key($user))
            ->where('expires_at', '>', CarbonImmutable::now())
            ->latest('expires_at')
            ->first();
    }

    /**
     * Spend the live bypass on one step-up. False when there is none, or it was spent.
     */
    public function spendOnStepUp(Authenticatable $user): bool
    {
        $bypass = $this->active($user);
        if (! $bypass instanceof SecurityBypass) {
            return false;
        }

        $claimed = SecurityBypass::query()
            ->whereKey($bypass->getKey())
            ->whereNull('step_up_used_at')
            ->update(['step_up_used_at' => CarbonImmutable::now()]);

        return $claimed === 1;
    }
}
