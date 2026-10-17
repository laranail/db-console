<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourceKind;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Events\AccountCreationRefused;
use Simtabi\Laranail\DBConsole\Events\DatabaseCreationRefused;
use Simtabi\Laranail\DBConsole\Exceptions\AccountAlreadyExists;
use Simtabi\Laranail\DBConsole\Exceptions\DatabaseAlreadyExists;
use Simtabi\Laranail\DBConsole\Security\Contracts\StepUpVerifier;

/**
 * The one place that decides what a create does about an account or database
 * that is already there (owner decision 2026-10-17: refuse and report, adopt
 * only on an authorised override). Every create path goes through it: the
 * account and database managers, and through them the wizard, the CLI and
 * the API.
 *
 * The policy is resolved most specific first: the call, then
 * `servers.<name>.<kind>s.on_existing`, then `<kind>s.on_existing`, then
 * Refuse. A configured value that is not a policy refuses rather than adopts.
 */
final readonly class ExistingResourceGuard
{
    public function __construct(
        private Config $config,
        private Authorizer $authorizer,
        private Gate $gate,
        private StepUpVerifier $stepUp,
        private Dispatcher $events,
        private DBConsoleLogger $log,
    ) {}

    public function resolve(ExistingResourceKind $kind, string $server, ?ExistingResourcePolicy $requested = null): ExistingResourcePolicy
    {
        $policy = $requested
            ?? $this->configured("laranail.db-console.servers.{$server}.{$kind->configSection()}.on_existing", $server)
            ?? $this->configured("laranail.db-console.{$kind->configSection()}.on_existing", $server)
            ?? ExistingResourcePolicy::default();

        return $policy->forKind($kind);
    }

    /**
     * The resource is already there: refuse it (report, then throw a 409), or
     * authorise the adoption the policy asks for and return that policy.
     * Nothing has changed on the server when this throws.
     */
    public function admit(ExistingResourceKind $kind, string $server, string $target, ?ExistingResourcePolicy $requested = null): ExistingResourcePolicy
    {
        $policy = $this->resolve($kind, $server, $requested);

        if ($policy === ExistingResourcePolicy::Refuse) {
            $this->refuse($kind, $server, $target);
        }

        $this->authorizer->authorize(ConsolePermission::ResourceAdopt, "server:{$server}");

        if ($policy === ExistingResourcePolicy::AdoptAndApply) {
            $this->authorizeApply($server);
        }

        return $policy;
    }

    /**
     * Applying a password to an account db-console did not create replaces a
     * credential something else may be using: it needs account.rotate and a
     * fresh step-up, on top of resource.adopt.
     */
    public function authorizeApply(string $server): void
    {
        $scope = "server:{$server}";

        $this->authorizer->authorize(ConsolePermission::AccountRotate, $scope);
        $this->stepUp->verify(ConsolePermission::AccountRotate, $scope);
    }

    /**
     * The adopting policies the current operator may resubmit with. Read from
     * the gate directly, so asking is not itself recorded as a denial.
     *
     * @return list<string>
     */
    public function optionsFor(ExistingResourceKind $kind, string $server): array
    {
        $scope = "server:{$server}";
        if (! $this->gate->allows(ConsolePermission::ResourceAdopt->ability(), $scope)) {
            return [];
        }

        $options = [ExistingResourcePolicy::Adopt->value];
        if (
            in_array(ExistingResourcePolicy::AdoptAndApply, ExistingResourcePolicy::casesFor($kind), true)
            && $this->gate->allows(ConsolePermission::AccountRotate->ability(), $scope)
        ) {
            $options[] = ExistingResourcePolicy::AdoptAndApply->value;
        }

        return $options;
    }

    private function refuse(ExistingResourceKind $kind, string $server, string $target): never
    {
        $options = $this->optionsFor($kind, $server);
        $context = ['target' => $target, 'options' => $options];

        if ($kind === ExistingResourceKind::Account) {
            $this->events->dispatch(new AccountCreationRefused($server, $context));

            throw AccountAlreadyExists::on($server, $target, $options);
        }

        $this->events->dispatch(new DatabaseCreationRefused($server, $context));

        throw DatabaseAlreadyExists::on($server, $target, $options);
    }

    private function configured(string $key, string $server): ?ExistingResourcePolicy
    {
        $value = $this->config->get($key);
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof ExistingResourcePolicy) {
            return $value;
        }

        $policy = is_string($value) ? ExistingResourcePolicy::tryFrom($value) : null;
        if ($policy instanceof ExistingResourcePolicy) {
            return $policy;
        }

        // Fail safe: a typo must never become an adoption.
        $this->log->write(Severity::Warning, 'config.invalid_on_existing', [
            'server'   => $server,
            'key'      => $key,
            'expected' => ExistingResourcePolicy::values(),
        ]);

        return ExistingResourcePolicy::Refuse;
    }
}
