<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Engines\Engine;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Events\AccountAdopted;
use Simtabi\Laranail\DBConsole\Events\AccountCreated;
use Simtabi\Laranail\DBConsole\Events\AccountDropped;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Servers\AdminConnection;
use Simtabi\Laranail\DBConsole\Engines\HostScopingEngine;
use Simtabi\Laranail\DBConsole\Events\AccountHostChanged;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourceKind;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;
use Simtabi\Laranail\DBConsole\Services\Contracts\Catalog;
use Simtabi\Laranail\DBConsole\Services\Wizard\WizardStep;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Events\AccountPasswordApplied;
use Simtabi\Laranail\DBConsole\Events\AccountPasswordRotated;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Exceptions\UnsupportedOperation;
use Simtabi\Laranail\DBConsole\Engines\OwnershipReleasingEngine;
use Simtabi\Laranail\DBConsole\Services\Results\OperationResult;
use Simtabi\Laranail\DBConsole\Events\OperationFailed as OperationFailedEvent;

/**
 * Creates, lists, drops, and rotates database accounts on a registered
 * server. Engines that do not manage accounts (SQLite) are refused up front
 * via the capability check, so the caller gets a clear UnsupportedOperation
 * rather than a driver error.
 */
final readonly class AccountManager
{
    public function __construct(
        private ServerRegistry $registry,
        private Authorizer $authorizer,
        private Dispatcher $events,
        private Catalog $catalog,
        private DBConsoleLogger $log,
        private WizardExecutor $executor,
        private PrivilegeManager $privileges,
        private OwnershipReleasingDrop $releasingDrop,
        private ExistingResourceGuard $guard,
    ) {}

    /**
     * Create an account. If $password is null a strong one is generated and
     * returned exactly once on the result.
     *
     * An account that is already there is handled by the existing-resource
     * policy ($onExisting, else the server's, else the global
     * `accounts.on_existing`, default refuse): refused with
     * AccountAlreadyExists (HTTP 409), adopted as it is, or adopted and given
     * $password (a generated one when null). The result's data says which:
     * `adopted` and `password_applied`.
     */
    public function create(
        string $server,
        Username $user,
        Host $host,
        ?Password $password = null,
        ?ExistingResourcePolicy $onExisting = null,
    ): OperationResult {
        $this->authorizer->authorize(ConsolePermission::AccountCreate, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);

        if (! $engine->capabilities()->canCreateAccount) {
            throw UnsupportedOperation::forEngine($engine->type()->value, 'account management');
        }

        if ($this->exists($server, $user, $host)) {
            return $this->adopt($server, $user, $host, $password, $onExisting);
        }

        $generated = null;
        if (! $password instanceof Password) {
            $password = Password::generate();
            $generated = $password->reveal();
        }

        try {
            $connection->run(
                $engine->createAccount($user, $host, $password),
                ['operation' => OperationType::AccountCreate->value, 'target' => $this->label($user, $host)],
            );
        } catch (DBConsoleException $e) {
            $this->fail(OperationType::AccountCreate, $server, $this->label($user, $host), $e);
        }

        $this->catalog->recordAccount($server, $user, $host);
        $this->log->success(OperationType::AccountCreate->value, $server, ['target' => $this->label($user, $host)]);
        $this->events->dispatch(new AccountCreated($server, ['target' => $this->label($user, $host)]));

        return OperationResult::succeeded(
            OperationType::AccountCreate,
            $server,
            ['username' => $user->value, 'host' => $host->value, 'adopted' => false, 'password_applied' => false],
            generatedPassword: $generated,
        );
    }

    /**
     * Give an adopted account $password, once the caller has been through
     * ExistingResourceGuard::authorizeApply(). The wizard adopts the account
     * in its account step, authorises the change there, and applies it as its
     * last step, so a failure in between leaves the credential as it was.
     *
     * @internal
     */
    public function applyAuthorizedPassword(string $server, Username $user, Host $host, ?Password $password = null): OperationResult
    {
        [$engine, $connection] = $this->registry->resolve($server);

        $generated = null;
        if (! $password instanceof Password) {
            $password = Password::generate();
            $generated = $password->reveal();
        }

        try {
            $connection->run(
                $engine->setPassword($user, $host, $password),
                ['operation' => OperationType::AccountPasswordApplied->value, 'target' => $this->label($user, $host)],
            );
        } catch (DBConsoleException $e) {
            $this->fail(OperationType::AccountPasswordApplied, $server, $this->label($user, $host), $e);
        }

        $this->catalog->recordPasswordRotation($server, $user, $host);
        $this->log->success(OperationType::AccountPasswordApplied->value, $server, ['target' => $this->label($user, $host)]);
        $this->events->dispatch(new AccountPasswordApplied($server, ['target' => $this->label($user, $host)]));

        return OperationResult::succeeded(
            OperationType::AccountCreate,
            $server,
            ['username' => $user->value, 'host' => $host->value, 'adopted' => true, 'password_applied' => true],
            alreadyExisted: true,
            generatedPassword: $generated,
        );
    }

    public function drop(string $server, Username $user, Host $host): OperationResult
    {
        $this->authorizer->authorize(ConsolePermission::AccountDrop, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);
        $this->requireAccounts($engine->type()->value, $engine->capabilities()->canCreateAccount);

        try {
            $this->dropAccount(
                $engine,
                $connection,
                $user,
                $host,
                ['operation' => OperationType::AccountDrop->value, 'target' => $this->label($user, $host)],
            );
        } catch (DBConsoleException $e) {
            $this->fail(OperationType::AccountDrop, $server, $this->label($user, $host), $e);
        }

        $this->catalog->forgetAccount($server, $user, $host);
        $this->log->success(OperationType::AccountDrop->value, $server, ['target' => $this->label($user, $host)]);
        $this->events->dispatch(new AccountDropped($server, ['target' => $this->label($user, $host)]));

        return OperationResult::succeeded(
            OperationType::AccountDrop,
            $server,
            ['username' => $user->value, 'host' => $host->value],
        );
    }

    /**
     * Rotate an account's password. If $password is null a strong one is
     * generated and returned exactly once.
     */
    public function rotatePassword(string $server, Username $user, Host $host, ?Password $password = null): OperationResult
    {
        $this->authorizer->authorize(ConsolePermission::AccountRotate, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);
        $this->requireAccounts($engine->type()->value, $engine->capabilities()->canRotatePassword);

        $generated = null;
        if (! $password instanceof Password) {
            $password = Password::generate();
            $generated = $password->reveal();
        }

        try {
            $connection->run(
                $engine->setPassword($user, $host, $password),
                ['operation' => OperationType::AccountRotate->value, 'target' => $this->label($user, $host)],
            );
        } catch (DBConsoleException $e) {
            $this->fail(OperationType::AccountRotate, $server, $this->label($user, $host), $e);
        }

        $this->catalog->recordPasswordRotation($server, $user, $host);
        $this->log->success(OperationType::AccountRotate->value, $server, ['target' => $this->label($user, $host)]);
        $this->events->dispatch(new AccountPasswordRotated($server, ['target' => $this->label($user, $host)]));

        return OperationResult::succeeded(
            OperationType::AccountRotate,
            $server,
            ['username' => $user->value, 'host' => $host->value],
            generatedPassword: $generated,
        );
    }

    /**
     * List accounts live from the server, without those its hosting reserves
     * (a managed provider's own accounts, the engine's system accounts), so
     * nothing offers to manage or drop them. exists() still sees them.
     *
     * @return list<string>
     */
    public function list(string $server): array
    {
        $this->authorizer->authorize(ConsolePermission::AccountView, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);
        $this->requireAccounts($engine->type()->value, $engine->capabilities()->canCreateAccount);

        $hosting = $this->registry->hosting($server);

        $accounts = [];
        foreach ($engine->listAccounts() as $statement) {
            foreach ($connection->select($statement->sql, ['operation' => 'account.view']) as $row) {
                $values = array_map(strval(...), array_values($row));

                if ($hosting->isReserved($values[0] ?? '')) {
                    continue;
                }

                $accounts[] = implode('@', $values);
            }
        }

        return $accounts;
    }

    public function exists(string $server, Username $user, Host $host): bool
    {
        [$engine, $connection] = $this->registry->resolve($server);
        if (! $engine->capabilities()->canCreateAccount) {
            return false;
        }

        foreach ($engine->listAccounts() as $statement) {
            foreach ($connection->select($statement->sql, ['operation' => 'account.view']) as $row) {
                $values = array_map(strval(...), array_values($row));
                $name = $values[0] ?? '';
                $rowHost = $values[1] ?? '';

                if ($name === $user->value && ($rowHost === '' || $rowHost === $host->value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Change an account's host (section 15). MySQL treats user@localhost and
     * user@% as distinct accounts, so this is a grant-preserving recreate:
     * read the auth hash + the catalog grants, create the account at the new
     * host (same password via the hash, or a fresh one if $rotate), re-apply
     * the grants, then drop the old-host account — all through WizardExecutor,
     * so a failure leaves the original account intact. Audited as a single
     * account.host_changed event with before/after (no secrets).
     */
    public function changeHost(string $server, Username $user, Host $oldHost, Host $newHost, bool $rotate = false): OperationResult
    {
        $this->authorizer->authorize(ConsolePermission::AccountEdit, "server:{$server}");

        [$engine, $connection] = $this->registry->resolve($server);

        if (! $engine->capabilities()->canScopeAccountsByHost || ! $engine instanceof HostScopingEngine) {
            throw UnsupportedOperation::forEngine($engine->type()->value, 'host change');
        }

        $grants = $this->catalog->grantsForAccount($server, $user, $oldHost);
        $generated = null;
        $newPassword = null;
        if ($rotate) {
            $newPassword = Password::generate();
            $generated = $newPassword->reveal();
        }

        $this->executor->execute($server, OperationType::AccountHostChanged, [
            WizardStep::make(
                "create {$user->value}@{$newHost->value}",
                function () use ($engine, $connection, $user, $newHost, $oldHost, $rotate, $newPassword): void {
                    $statements = $rotate && $newPassword instanceof Password
                        ? $engine->createAccount($user, $newHost, $newPassword)
                        : $this->recreateWithPreservedAuth($engine, $connection, $user, $oldHost, $newHost);

                    $connection->run($statements, [
                        'operation' => OperationType::AccountHostChanged->value,
                        'target'    => "{$user->value}@{$newHost->value}",
                    ]);
                },
                function () use ($engine, $connection, $user, $newHost): void {
                    $connection->run($engine->dropAccount($user, $newHost), [
                        'operation' => OperationType::AccountDrop->value,
                        'target'    => "{$user->value}@{$newHost->value}",
                        'rollback'  => true,
                    ]);
                },
            ),
            WizardStep::make(
                're-apply grants',
                function () use ($server, $user, $newHost, $grants): void {
                    foreach ($grants as $grant) {
                        $this->privileges->grant($server, $user, $newHost, $grant->database, $grant->privileges);
                    }
                },
            ),
            WizardStep::make(
                "drop {$user->value}@{$oldHost->value}",
                function () use ($engine, $connection, $user, $oldHost): void {
                    $connection->run($engine->dropAccount($user, $oldHost), [
                        'operation' => OperationType::AccountDrop->value,
                        'target'    => "{$user->value}@{$oldHost->value}",
                    ]);
                },
            ),
        ]);

        $this->catalog->recordHostChange($server, $user, $oldHost, $newHost);
        $this->log->success(OperationType::AccountHostChanged->value, $server, [
            'target' => $user->value,
            'from'   => $oldHost->value,
            'to'     => $newHost->value,
        ]);
        $this->events->dispatch(new AccountHostChanged($server, [
            'target' => $user->value,
            'from'   => $oldHost->value,
            'to'     => $newHost->value,
        ]));

        return OperationResult::succeeded(
            OperationType::AccountHostChanged,
            $server,
            ['username' => $user->value, 'from' => $oldHost->value, 'to' => $newHost->value],
            generatedPassword: $generated,
        );
    }

    /**
     * Compensating rollback for an account this run created: drop it. No gate
     * — a system rollback undoing the wizard's own forward step, not a
     * user-initiated drop. Used exclusively by WizardExecutor.
     *
     * @internal
     */
    public function rollbackCreatedAccount(string $server, Username $user, Host $host): void
    {
        [$engine, $connection] = $this->registry->resolve($server);
        if (! $engine->capabilities()->canCreateAccount) {
            return;
        }

        $this->dropAccount(
            $engine,
            $connection,
            $user,
            $host,
            ['operation' => OperationType::AccountDrop->value, 'target' => $this->label($user, $host), 'rollback' => true],
        );
        $this->catalog->forgetAccount($server, $user, $host);
    }

    /**
     * Drop an account. Where the engine cannot drop one that still owns
     * objects or holds privileges (Postgres), OwnershipReleasingDrop releases
     * them first and undoes what it took when a step fails.
     *
     * @param array<string, mixed> $context
     */
    private function dropAccount(Engine $engine, AdminConnection $connection, Username $user, Host $host, array $context): void
    {
        if ($engine instanceof OwnershipReleasingEngine) {
            $this->releasingDrop->drop($engine, $connection, $user, $host, $context);

            return;
        }

        $connection->run($engine->dropAccount($user, $host), $context);
    }

    /**
     * Build the create-account statements that preserve the existing
     * password by copying its stored hash (never the plaintext, which
     * DBConsole does not hold).
     */
    private function recreateWithPreservedAuth(
        HostScopingEngine&Engine $engine,
        AdminConnection $connection,
        Username $user,
        Host $oldHost,
        Host $newHost,
    ): StatementList {
        $read = $engine->readAuthentication($user, $oldHost);
        $rows = [];
        foreach ($read as $statement) {
            $rows = $connection->select($statement->sql, ['operation' => 'account.view']);
        }

        $row = $rows[0] ?? null;
        if ($row === null) {
            throw UnsupportedOperation::forEngine($engine->type()->value, 'host change (account not found)');
        }

        $plugin = (string) ($row['plugin'] ?? 'caching_sha2_password');
        $hashLiteral = (string) ($row['auth'] ?? '0x');

        // An empty-password account ('0x') recreates with no IDENTIFIED WITH
        // hash — fall back to a plain create with a generated password so the
        // account is never left passwordless.
        if ($hashLiteral === '0x' || $hashLiteral === '') {
            return $engine->createAccount($user, $newHost, Password::generate());
        }

        return $engine->createAccountWithAuth($user, $newHost, $plugin, $hashLiteral);
    }

    /**
     * The account is already there: refuse it, or adopt it (and, under
     * adopt_and_apply, give it the supplied password) as the policy says.
     * The guard authorises everything before anything changes.
     */
    private function adopt(string $server, Username $user, Host $host, ?Password $password, ?ExistingResourcePolicy $onExisting): OperationResult
    {
        $policy = $this->guard->admit(ExistingResourceKind::Account, $server, $this->label($user, $host), $onExisting);

        $applied = null;
        if ($policy === ExistingResourcePolicy::AdoptAndApply) {
            // Apply first: if the server refuses the new password, nothing is
            // recorded as adopted.
            $applied = $this->applyAuthorizedPassword($server, $user, $host, $password);
        }

        $this->catalog->recordAccount($server, $user, $host);
        $this->log->success(OperationType::AccountAdopted->value, $server, ['target' => $this->label($user, $host)]);
        $this->events->dispatch(new AccountAdopted($server, [
            'target'           => $this->label($user, $host),
            'password_applied' => $applied instanceof OperationResult,
        ]));

        return $applied ?? OperationResult::succeeded(
            OperationType::AccountCreate,
            $server,
            ['username' => $user->value, 'host' => $host->value, 'adopted' => true, 'password_applied' => false],
            alreadyExisted: true,
        );
    }

    private function requireAccounts(string $engine, bool $capable): void
    {
        if (! $capable) {
            throw UnsupportedOperation::forEngine($engine, 'account management');
        }
    }

    private function label(Username $user, Host $host): string
    {
        return "{$user->value}@{$host->value}";
    }

    private function fail(OperationType $operation, string $server, string $target, DBConsoleException $e): never
    {
        $this->log->failure($operation->value, $server, $e);
        $this->events->dispatch(new OperationFailedEvent($server, $operation, [
            'target' => $target,
            'code'   => $e->code()->value,
        ]));

        throw $e;
    }
}
