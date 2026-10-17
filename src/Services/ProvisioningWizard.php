<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourceKind;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Services\Wizard\WizardStep;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Services\Results\OperationResult;

/**
 * The guided create-database + create-account + grant flow (Wizard 1, the
 * CLI `wizard`, scenario B). It composes the three managers as forward steps
 * and provides safe compensations, running the whole thing through the
 * WizardExecutor so a mid-sequence failure rolls back cleanly — dropping only
 * what this run created, and a database only if it is still empty.
 *
 * Each forward step is a normal, gated manager call (so authorization, audit,
 * and events happen per operation); the compensations are system rollbacks
 * (no gate) invoked only on failure.
 */
final readonly class ProvisioningWizard
{
    public function __construct(
        private WizardExecutor $executor,
        private DatabaseManager $databases,
        private AccountManager $accounts,
        private PrivilegeManager $privileges,
        private ExistingResourceGuard $guard,
    ) {}

    /**
     * Provision a database, an account, and a grant atomically (via
     * compensating rollback). Returns the create-account result, which
     * carries the generated password once when none was supplied.
     *
     * A database or account that is already there follows the existing-
     * resource policy ($onExisting, else configured, default refuse). Two
     * rules keep a re-run safe:
     *  - without a password the wizard never replaces an adopted account's
     *    credential: adopt_and_apply is treated as adopt;
     *  - with one, the right to apply it (account.rotate and step-up) is
     *    checked when the account is adopted, before the grant, and the
     *    password itself is applied last, so any earlier failure leaves the
     *    account's credential as it was.
     * A rollback never drops what was adopted, only what this run created.
     */
    public function provision(
        string $server,
        DbName $database,
        Charset $charset,
        Username $username,
        Host $host,
        PrivilegeSet $privileges,
        ?Password $password = null,
        ?ExistingResourcePolicy $onExisting = null,
    ): OperationResult {
        $accountPolicy = $this->guard->resolve(ExistingResourceKind::Account, $server, $onExisting);
        if (! $password instanceof Password && $accountPolicy === ExistingResourcePolicy::AdoptAndApply) {
            $accountPolicy = ExistingResourcePolicy::Adopt;
        }

        // Filled by the forward steps, read by their compensations.
        /** @var OperationResult|null $databaseResult */
        $databaseResult = null;
        /** @var OperationResult|null $accountResult */
        $accountResult = null;
        $applyPassword = false;

        $this->executor->execute($server, OperationType::DatabaseCreate, [
            WizardStep::make(
                "create database {$database->value}",
                function () use (&$databaseResult, $server, $database, $charset, $onExisting): OperationResult {
                    return $databaseResult = $this->databases->create($server, $database, $charset, $onExisting);
                },
                // An existing database or account is adopted, not created, so
                // a rollback leaves it alone: undoing the run must not drop
                // what was there before it.
                function () use (&$databaseResult, $server, $database): void {
                    if ($databaseResult instanceof OperationResult && ! $databaseResult->alreadyExisted) {
                        $this->databases->rollbackCreatedDatabase($server, $database);
                    }
                },
            ),
            WizardStep::make(
                "create account {$username->value}@{$host->value}",
                function () use (&$accountResult, &$applyPassword, $server, $username, $host, $password, $accountPolicy): OperationResult {
                    $adoptOnly = $accountPolicy === ExistingResourcePolicy::AdoptAndApply ? ExistingResourcePolicy::Adopt : $accountPolicy;
                    $accountResult = $this->accounts->create($server, $username, $host, $password, $adoptOnly);

                    if ($accountResult->alreadyExisted && $accountPolicy === ExistingResourcePolicy::AdoptAndApply) {
                        $this->guard->authorizeApply($server);
                        $applyPassword = true;
                    }

                    return $accountResult;
                },
                function () use (&$accountResult, $server, $username, $host): void {
                    if ($accountResult instanceof OperationResult && ! $accountResult->alreadyExisted) {
                        $this->accounts->rollbackCreatedAccount($server, $username, $host);
                    }
                },
            ),
            WizardStep::make(
                "grant {$privileges->preset->value} on {$database->value}",
                fn (): OperationResult => $this->privileges->grant($server, $username, $host, $database, $privileges),
            ),
            WizardStep::make(
                "apply the password to {$username->value}@{$host->value}",
                function () use (&$accountResult, &$applyPassword, $server, $username, $host, $password): ?OperationResult {
                    if (! $applyPassword) {
                        return null;
                    }

                    return $accountResult = $this->accounts->applyAuthorizedPassword($server, $username, $host, $password);
                },
            ),
        ]);

        /** @var OperationResult $accountResult */
        return $accountResult;
    }
}
