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
 * db-console's own assurance requirements are lifted for that operator, so a
 * host-side problem (a lost device, a broken confirmation flow) never locks
 * the last Owner out of db-console. It does not touch the host's login. Issuing
 * a new bypass ends any earlier one, and every issue raises a critical alert.
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
}
