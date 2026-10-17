<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use DateTimeInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Enums\Assurance;
use Illuminate\Contracts\Cache\Repository as Cache;
use Simtabi\Laranail\DBConsole\Models\SecurityBypass;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Events\AssuranceShortfall;
use Simtabi\Laranail\DBConsole\Enums\AssuranceEnforcement;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;
use Simtabi\Laranail\DBConsole\Exceptions\AssuranceRequired;

/**
 * Checks an authenticated operator's host-reported assurance against what an
 * operation requires (SecurityPolicy::requiredFor), after RBAC has allowed it.
 * Called only from the Authorizer, the core's single authorization entry
 * point, so the API, the WebUI and the CLI get the same answer:
 *
 *   no operator, enforcement off, nothing required, or a live break-glass → allowed
 *   report  → allowed, the shortfall audited
 *   require → AssuranceRequired, naming what is missing
 *
 * db-console verifies nothing itself: the host's OperatorAssurance answers.
 * A run with no operator was already decided by the gate.
 */
final readonly class AssuranceEnforcer
{
    private const int ALERT_EVERY_SECONDS = 86400;

    public function __construct(
        private SecurityPolicy $policy,
        private OperatorAssurance $assurance,
        private BreakGlass $breakGlass,
        private Dispatcher $events,
        private Cache $cache,
    ) {}

    /**
     * $alsoRequire adds to what the policy asks of $permission, for an
     * operation that must need an assurance however the policy is configured
     * (applying a password to an adopted account always needs a recent
     * confirmation). It is checked once, with the rest, so one shortfall is
     * audited and one exception thrown.
     *
     * @param list<Assurance> $alsoRequire
     *
     * @throws AssuranceRequired
     */
    public function enforce(?Authenticatable $operator, ConsolePermission $permission, string $server = 'global', array $alsoRequire = []): void
    {
        if (! $operator instanceof Authenticatable) {
            return;
        }

        $mode = $this->policy->enforcement();
        if ($mode === AssuranceEnforcement::Off) {
            return;
        }

        $required = $this->policy->requiredFor($permission);
        foreach ($alsoRequire as $assurance) {
            if (! in_array($assurance, $required, true)) {
                $required[] = $assurance;
            }
        }

        $missing = $this->missing($operator, $required);
        if ($missing === [] || $this->breakGlass->active($operator) instanceof SecurityBypass) {
            return;
        }

        $verdict = $mode === AssuranceEnforcement::Require ? AssuranceShortfall::REFUSED : AssuranceShortfall::WOULD_REFUSE;
        $label = OperatorIdentity::label($operator);

        $this->events->dispatch(new AssuranceShortfall(
            server: $server,
            operator: $label,
            verdict: $verdict,
            ability: $permission->ability(),
            missing: array_map(static fn (Assurance $a): string => $a->value, $missing),
            alert: $this->cache->add('laranail-db-console:assurance-alert:' . sha1($label . $verdict), true, self::ALERT_EVERY_SECONDS),
        ));

        if ($mode === AssuranceEnforcement::Require) {
            throw AssuranceRequired::for($permission->ability(), $missing);
        }
    }

    /**
     * Whether the operator has a second factor, as the host reports it.
     */
    public function hasSecondFactor(Authenticatable $operator): bool
    {
        return $this->assurance->hasSecondFactor($operator);
    }

    /**
     * @param list<Assurance> $required
     *
     * @return list<Assurance>
     */
    private function missing(Authenticatable $operator, array $required): array
    {
        $missing = [];
        foreach ($required as $assurance) {
            $met = match ($assurance) {
                Assurance::SecondFactor       => $this->assurance->hasSecondFactor($operator),
                Assurance::RecentConfirmation => $this->recentlyConfirmed($operator),
            };

            if (! $met) {
                $missing[] = $assurance;
            }
        }

        return $missing;
    }

    private function recentlyConfirmed(Authenticatable $operator): bool
    {
        $at = $this->assurance->recentlyConfirmedAt($operator);
        if (! $at instanceof DateTimeInterface) {
            return false;
        }

        $age = CarbonImmutable::now()->getTimestamp() - $at->getTimestamp();

        return $age >= 0 && $age <= $this->policy->stepUpTtl();
    }
}
