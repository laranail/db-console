<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Services\Results\OperationResult;
use Simtabi\Laranail\DBConsole\Services\Wizard\WizardStep;

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
    ) {}

    /**
     * Provision a database, an account, and a grant atomically (via
     * compensating rollback). Returns the create-account result, which
     * carries the generated password once when none was supplied.
     */
    public function provision(
        string $server,
        DbName $database,
        Charset $charset,
        Username $username,
        Host $host,
        PrivilegeSet $privileges,
        ?Password $password = null,
    ): OperationResult {
        $accountResult = null;

        $this->executor->execute($server, OperationType::DatabaseCreate, [
            WizardStep::make(
                "create database {$database->value}",
                fn (): OperationResult => $this->databases->create($server, $database, $charset),
                function () use ($server, $database): void {
                    $this->databases->rollbackCreatedDatabase($server, $database);
                },
            ),
            WizardStep::make(
                "create account {$username->value}@{$host->value}",
                function () use (&$accountResult, $server, $username, $host, $password): OperationResult {
                    return $accountResult = $this->accounts->create($server, $username, $host, $password);
                },
                function () use ($server, $username, $host): void {
                    $this->accounts->rollbackCreatedAccount($server, $username, $host);
                },
            ),
            WizardStep::make(
                "grant {$privileges->preset->value} on {$database->value}",
                fn (): OperationResult => $this->privileges->grant($server, $username, $host, $database, $privileges),
            ),
        ]);

        /** @var OperationResult $accountResult */
        return $accountResult;
    }
}
