<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Events\RollbackFailed as RollbackFailedEvent;
use Simtabi\Laranail\DBConsole\Events\RollbackPerformed;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Exceptions\RollbackFailed;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Services\ProvisioningWizard;
use Simtabi\Laranail\DBConsole\Services\Wizard\WizardStep;
use Simtabi\Laranail\DBConsole\Services\WizardExecutor;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    $this->registerMysqlServer();
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);

    $this->suffix = $this->uniqueSuffix();
    $this->db = "dbc_wiz_{$this->suffix}";
    $this->user = "dbc_wu_{$this->suffix}";
});

afterEach(function (): void {
    try {
        $conn = DB::connection('db_console_admin');
        $conn->statement("DROP DATABASE IF EXISTS `{$this->db}`");
        $conn->statement("DROP USER IF EXISTS '{$this->user}'@'%'");
    } catch (Throwable) {
    }
});

describe('the provisioning wizard (happy path)', function (): void {
    it('creates database + account + grant and returns the generated password once', function (): void {
        $result = app(ProvisioningWizard::class)->provision(
            'docker-mysql',
            new DbName($this->db),
            new Charset('utf8mb4', 'utf8mb4_unicode_ci'),
            new Username($this->user),
            new Host('%'),
            PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        );

        expect($result->hasGeneratedPassword())->toBeTrue()
            ->and(app(DatabaseManager::class)->exists('docker-mysql', new DbName($this->db)))->toBeTrue()
            ->and(app(AccountManager::class)->exists('docker-mysql', new Username($this->user), new Host('%')))->toBeTrue();
    });
});

describe('compensating rollback on a mid-sequence failure (section 14)', function (): void {
    it('rolls back the account and the (empty) database when a later step fails', function (): void {
        Event::fake([RollbackPerformed::class, RollbackFailedEvent::class]);

        $databases = app(DatabaseManager::class);
        $accounts = app(AccountManager::class);
        $executor = app(WizardExecutor::class);

        $db = new DbName($this->db);
        $user = new Username($this->user);
        $host = new Host('%');

        // Steps: create db (ok) → create account (ok) → FAIL.
        expect(fn () => $executor->execute('docker-mysql', OperationType::DatabaseCreate, [
            WizardStep::make(
                'create db',
                fn () => $databases->create('docker-mysql', $db, new Charset('utf8mb4')),
                fn () => $databases->rollbackCreatedDatabase('docker-mysql', $db),
            ),
            WizardStep::make(
                'create account',
                fn () => $accounts->create('docker-mysql', $user, $host),
                fn () => $accounts->rollbackCreatedAccount('docker-mysql', $user, $host),
            ),
            WizardStep::make('boom', function (): void {
                throw new RuntimeException('forced mid-sequence failure');
            }),
        ]))->toThrow(DBConsoleException::class);

        // Both objects created this run were undone; nothing pre-existing was touched.
        expect($databases->exists('docker-mysql', $db))->toBeFalse()
            ->and($accounts->exists('docker-mysql', $user, $host))->toBeFalse();

        Event::assertDispatched(RollbackPerformed::class);
        Event::assertNotDispatched(RollbackFailedEvent::class);
    });

    it('never drops a database that is not empty (protects pre-existing data)', function (): void {
        $databases = app(DatabaseManager::class);
        $db = new DbName($this->db);

        // Pre-create the database with a table (simulating data that predates
        // — or was created during — the run).
        $databases->create('docker-mysql', $db, new Charset('utf8mb4'));
        DB::connection('db_console_admin')->statement("CREATE TABLE `{$this->db}`.keep_me (id INT)");

        // A rollback attempt must refuse to drop the non-empty database.
        $databases->rollbackCreatedDatabase('docker-mysql', $db);

        expect($databases->exists('docker-mysql', $db))->toBeTrue()
            ->and($databases->isEmpty('docker-mysql', $db))->toBeFalse();
    });
});

describe('RollbackFailed escalation (section 10)', function (): void {
    it('escalates to RollbackFailed (critical + alert) when a compensation itself fails', function (): void {
        Event::fake([RollbackFailedEvent::class]);

        $executor = app(WizardExecutor::class);

        expect(fn () => $executor->execute('docker-mysql', OperationType::AccountCreate, [
            WizardStep::make(
                'step that will need compensating',
                fn (): string => 'ok',
                function (): void {
                    // A compensation that itself fails → RollbackFailed.
                    throw new RuntimeException('compensation blew up');
                },
            ),
            WizardStep::make('failing forward step', function (): void {
                throw new RuntimeException('forward failure triggers rollback');
            }),
        ]))->toThrow(RollbackFailed::class);

        Event::assertDispatched(RollbackFailedEvent::class);
    });
});
