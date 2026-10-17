<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Models\SecurityBypass;
use Simtabi\Laranail\DBConsole\Models\TwoFactorGrace;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Events\TwoFactorEnforced;
use Simtabi\Laranail\DBConsole\Enums\TwoFactorEnforcement;
use Simtabi\Laranail\DBConsole\Exceptions\TwoFactorRequired;
use Simtabi\Laranail\DBConsole\Security\Contracts\TwoFactorStatus;
use Simtabi\Laranail\DBConsole\Notifications\TwoFactorEnrolmentNotification;

/**
 * Applies `security.two_factor.enforce` to an authenticated operator. Called
 * from the core's Authorizer (after the gate allows) and from the API guard,
 * so every surface is covered by one rule:
 *
 *   enrolled (confirmed 2FA and recovery codes), or a live break-glass → allowed
 *   off     → allowed
 *   report  → allowed; what require would refuse is logged, and alerted once a day
 *   require → the first refusal opens the grace window; inside it reads pass and
 *             writes are refused; after it everything is refused
 *
 * The operator and the security recipients are told at most once a day.
 * A run with no operator is not this class's decision: the gate already made it.
 */
final readonly class TwoFactorEnforcer
{
    private const int NOTIFY_EVERY_HOURS = 24;

    public function __construct(
        private SecurityPolicy $policy,
        private TwoFactorStatus $status,
        private BreakGlass $breakGlass,
        private Dispatcher $events,
        private DBConsoleLogger $log,
    ) {}

    /**
     * @throws TwoFactorRequired
     */
    public function enforce(?Authenticatable $user, string $ability, bool $read): void
    {
        if (! $user instanceof Authenticatable) {
            return;
        }

        $mode = $this->policy->enforcement();
        if ($mode === TwoFactorEnforcement::Off || $this->isEnrolled($user)) {
            return;
        }

        if ($this->breakGlass->active($user) instanceof SecurityBypass) {
            return;
        }

        if ($mode === TwoFactorEnforcement::Report) {
            $this->report($user, $ability);

            return;
        }

        $now = CarbonImmutable::now();
        $grace = $this->graceFor($user);
        if ($grace->first_refused_at === null) {
            $grace->first_refused_at = $now;
            $grace->save();
        }

        $endsAt = $grace->first_refused_at->addDays($this->policy->graceDays());
        $inGrace = $now->lessThan($endsAt);

        $this->tell($user, $grace, TwoFactorEnforced::REFUSED, $ability, $inGrace ? $endsAt->toAtomString() : null);

        if ($inGrace && $read) {
            return;
        }

        throw $inGrace
            ? TwoFactorRequired::duringGrace($ability, $endsAt)
            : TwoFactorRequired::afterGrace($ability);
    }

    /**
     * Enrolment is only complete with recovery codes, so losing the device is
     * never a lockout.
     */
    public function isEnrolled(Authenticatable $user): bool
    {
        return $this->status->hasConfirmedTwoFactor($user) && $this->status->hasRecoveryCodes($user);
    }

    private function report(Authenticatable $user, string $ability): void
    {
        $this->log->write(Severity::Notice, 'security.two_factor.would_refuse', [
            'operator' => OperatorIdentity::label($user),
            'ability'  => $ability,
        ]);

        $grace = $this->graceFor($user);
        if ($grace->reported_at !== null && $grace->reported_at->greaterThan(CarbonImmutable::now()->subHours(self::NOTIFY_EVERY_HOURS))) {
            return;
        }

        $grace->reported_at = CarbonImmutable::now();
        $grace->save();

        $this->announce($user, TwoFactorEnforced::WOULD_REFUSE, $ability, null);
    }

    private function tell(Authenticatable $user, TwoFactorGrace $grace, string $verdict, string $ability, ?string $graceEndsAt): void
    {
        if ($grace->notified_at !== null && $grace->notified_at->greaterThan(CarbonImmutable::now()->subHours(self::NOTIFY_EVERY_HOURS))) {
            return;
        }

        $grace->notified_at = CarbonImmutable::now();
        $grace->save();

        $this->announce($user, $verdict, $ability, $graceEndsAt);
    }

    private function announce(Authenticatable $user, string $verdict, string $ability, ?string $graceEndsAt): void
    {
        $event = new TwoFactorEnforced(
            operator: OperatorIdentity::label($user),
            verdict: $verdict,
            ability: $ability,
            graceEndsAt: $graceEndsAt,
        );

        // The security recipients hear about it through the event pipeline (warning severity).
        $this->events->dispatch($event);

        if (method_exists($user, 'notify')) {
            $user->notify(new TwoFactorEnrolmentNotification($event));
        }
    }

    private function graceFor(Authenticatable $user): TwoFactorGrace
    {
        return TwoFactorGrace::query()->firstOrCreate(OperatorIdentity::key($user));
    }
}
