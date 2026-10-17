<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Simtabi\Laranail\DBConsole\Events\StepUpFailed;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Simtabi\Laranail\DBConsole\Enums\SensitiveAction;
use Simtabi\Laranail\DBConsole\Exceptions\StepUpRequired;
use Simtabi\Laranail\DBConsole\Security\Contracts\StepUpVerifier;

/**
 * Asks for step-up before a dangerous action. Services call require() right
 * after the Authorizer, so the check holds on every surface:
 *
 *   no operator, in the host's shell  → allowed (shell access is the trust boundary)
 *   no operator, over HTTP            → refused
 *   already verified in this scope    → allowed
 *   the configured verifier passes    → allowed
 *   an unspent break-glass bypass     → allowed, once
 *   otherwise                         → StepUpRequired, and a security alert
 *
 * The proof is scoped per request, so it is resolved on each call rather than
 * held by this singleton.
 */
final readonly class StepUpGate
{
    public function __construct(
        private SecurityPolicy $policy,
        private StepUpVerifier $verifier,
        private BreakGlass $breakGlass,
        private AuthFactory $auth,
        private Container $container,
        private Application $app,
        private Dispatcher $events,
    ) {}

    /**
     * @throws StepUpRequired
     */
    public function require(SensitiveAction $action, string $server = 'global'): void
    {
        $user = $this->auth->guard()->user();
        if (! $user instanceof Authenticatable) {
            if ($this->app->runningInConsole()) {
                return;
            }

            throw StepUpRequired::noOperator($action);
        }

        $proof = $this->proof();
        if ($proof->isSatisfied()) {
            return;
        }

        if ($this->verifier->verified($user, $proof) || $this->breakGlass->spendOnStepUp($user)) {
            $proof->markSatisfied();

            return;
        }

        $driver = $this->policy->stepUpDriver();
        $this->events->dispatch(new StepUpFailed(
            server: $server,
            operator: OperatorIdentity::label($user),
            action: $action->value,
            driver: $driver->value,
            proofPresented: $proof->otp() !== null,
        ));

        throw StepUpRequired::for($action, $driver);
    }

    private function proof(): StepUpProof
    {
        return $this->container->make(StepUpProof::class);
    }
}
