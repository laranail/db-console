<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\Assurance;
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
use Simtabi\Laranail\DBConsole\Exceptions\AuthorizationException;

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
     * Nothing has changed on the server when this throws, and a refusal of
     * either kind (the policy's, or an operator who may not adopt) is
     * reported to the security recipients.
     */
    public function admit(ExistingResourceKind $kind, string $server, string $target, ?ExistingResourcePolicy $requested = null): ExistingResourcePolicy
    {
        $policy = $this->resolve($kind, $server, $requested);

        if ($policy === ExistingResourcePolicy::Refuse) {
            $this->refuse($kind, $server, $target);
        }

        $this->reportingRefusal($kind, $server, $target, $policy, function () use ($server, $policy): void {
            $this->authorizer->authorize(ConsolePermission::ResourceAdopt, "server:{$server}");

            if ($policy === ExistingResourcePolicy::AdoptAndApply) {
                $this->demandApply($server);
            }
        });

        return $policy;
    }

    /**
     * Applying a password to an account db-console did not create replaces a
     * credential something else may be using. It needs account.rotate on top
     * of resource.adopt, and the assurance policy (security.assurance) is
     * asked for a recent confirmation whatever it lists for account.rotate:
     * the same enforcement mode, break-glass and AssuranceRequired as every
     * other permission. With $target, a refusal is reported like admit()'s.
     */
    public function authorizeApply(string $server, ?string $target = null): void
    {
        if ($target === null) {
            $this->demandApply($server);

            return;
        }

        $this->reportingRefusal(
            ExistingResourceKind::Account,
            $server,
            $target,
            ExistingResourcePolicy::AdoptAndApply,
            fn () => $this->demandApply($server),
        );
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
        $this->reportRefusal($kind, $server, $target, [
            'options'   => $options,
            'reason'    => 'policy',
            'requested' => ExistingResourcePolicy::Refuse->value,
        ]);

        if ($kind === ExistingResourceKind::Account) {
            throw AccountAlreadyExists::on($server, $target, $options);
        }

        throw DatabaseAlreadyExists::on($server, $target, $options);
    }

    private function demandApply(string $server): void
    {
        $this->authorizer->authorize(ConsolePermission::AccountRotate, "server:{$server}", [Assurance::RecentConfirmation]);
    }

    /**
     * Run an authorisation check, and report a denial as a refusal before
     * letting it propagate: "refuse and report" holds for an operator who
     * asked to adopt and was not allowed to, too.
     *
     * @param Closure(): void $check
     */
    private function reportingRefusal(ExistingResourceKind $kind, string $server, string $target, ExistingResourcePolicy $requested, Closure $check): void
    {
        try {
            $check();
        } catch (AuthorizationException $e) {
            $this->reportRefusal($kind, $server, $target, [
                'options'   => $this->optionsFor($kind, $server),
                'reason'    => $e->code()->value,
                'requested' => $requested->value,
            ]);

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reportRefusal(ExistingResourceKind $kind, string $server, string $target, array $context): void
    {
        $context = ['target' => $target, ...$context];

        $this->events->dispatch($kind === ExistingResourceKind::Account
            ? new AccountCreationRefused($server, $context)
            : new DatabaseCreationRefused($server, $context));
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
