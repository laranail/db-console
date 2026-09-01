<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\ApiAuthGuard;
use Simtabi\Laranail\DBConsole\Enums\AtRestStatus;
use Simtabi\Laranail\DBConsole\Enums\CatalogEncryptionMode;
use Simtabi\Laranail\DBConsole\Enums\Charset;
use Simtabi\Laranail\DBConsole\Enums\Collation;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;
use Simtabi\Laranail\DBConsole\Enums\ForbiddenPrivilege;
use Simtabi\Laranail\DBConsole\Enums\GrantScope;
use Simtabi\Laranail\DBConsole\Enums\HostScopeMode;
use Simtabi\Laranail\DBConsole\Enums\KmsProvider;
use Simtabi\Laranail\DBConsole\Enums\NotificationCategory;
use Simtabi\Laranail\DBConsole\Enums\OperationOutcome;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Enums\Privilege;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Enums\RbacDriver;
use Simtabi\Laranail\DBConsole\Enums\ScopeType;
use Simtabi\Laranail\DBConsole\Enums\SecretDriver;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\TlsStatus;
use Simtabi\Laranail\DBConsole\Enums\VaultAuthMethod;
use Simtabi\Laranail\DBConsole\Enums\WebhookEvent;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

/** @return list<class-string> */
function allDBConsoleEnums(): array
{
    return [
        EngineType::class, PrivilegePreset::class, Privilege::class,
        ForbiddenPrivilege::class, GrantScope::class, HostScopeMode::class,
        Charset::class, Collation::class, SecretDriver::class,
        KmsProvider::class, VaultAuthMethod::class, CatalogEncryptionMode::class,
        AtRestStatus::class, TlsStatus::class, RbacDriver::class,
        ConsoleRole::class, ConsolePermission::class, ScopeType::class,
        ApiAuthGuard::class, WebhookEvent::class, OperationType::class,
        OperationOutcome::class, Severity::class, NotificationCategory::class,
        ExceptionCode::class,
    ];
}

it('every DBConsole enum is a laranail/enumerator enum in the db-console translation namespace', function (): void {
    foreach (allDBConsoleEnums() as $enum) {
        expect(is_subclass_of($enum, Enumerator::class))->toBeTrue("{$enum} must implement Enumerator")
            ->and(is_subclass_of($enum, Translatable::class))->toBeTrue("{$enum} must implement Translatable")
            ->and($enum::translationNamespace())->toBe('db-console');
    }
});

it('EngineType covers exactly the five Laravel engines', function (): void {
    expect(EngineType::values())->toBe(['mysql', 'mariadb', 'pgsql', 'sqlsrv', 'sqlite']);
});

it('resolves labels through the enumerator toolkit', function (): void {
    expect(EngineType::Mysql->label())->toBe('MySQL')
        ->and(EngineType::Pgsql->label())->toBe('PostgreSQL')
        ->and(EngineType::options())->toHaveKey('mysql', 'MySQL');
});

it('ConsolePermission abilities carry the db-console gate prefix', function (): void {
    expect(ConsolePermission::Access->ability())->toBe('db-console.access')
        ->and(ConsolePermission::DatabaseDrop->ability())->toBe('db-console.database.drop')
        ->and(ConsolePermission::cases())->toHaveCount(21);
});

describe('shipped role composition (section 17 table)', function (): void {
    it('Owner has everything, including secrets and settings', function (): void {
        expect(ConsoleRole::Owner->permissions())->toBe(ConsolePermission::cases());
    });

    it('Admin has everything except secrets and settings management', function (): void {
        $permissions = ConsoleRole::Admin->permissions();

        expect($permissions)->not->toContain(ConsolePermission::SecretsManage)
            ->and($permissions)->not->toContain(ConsolePermission::SettingsManage)
            ->and($permissions)->toContain(ConsolePermission::DatabaseDrop)
            ->and($permissions)->toHaveCount(19);
    });

    it('Operator can create/grant/attach/rotate but never drop, revoke, or touch secrets', function (): void {
        $permissions = ConsoleRole::Operator->permissions();

        expect($permissions)->toContain(ConsolePermission::DatabaseCreate)
            ->and($permissions)->toContain(ConsolePermission::AccountCreate)
            ->and($permissions)->toContain(ConsolePermission::GrantCreate)
            ->and($permissions)->toContain(ConsolePermission::Attach)
            ->and($permissions)->toContain(ConsolePermission::AccountRotate)
            ->and($permissions)->not->toContain(ConsolePermission::DatabaseDrop)
            ->and($permissions)->not->toContain(ConsolePermission::AccountDrop)
            ->and($permissions)->not->toContain(ConsolePermission::GrantRevoke)
            ->and($permissions)->not->toContain(ConsolePermission::Detach)
            ->and($permissions)->not->toContain(ConsolePermission::SecretsManage);
    });

    it('ReadOnly is views plus audit access only', function (): void {
        foreach (ConsoleRole::ReadOnly->permissions() as $permission) {
            expect(
                $permission === ConsolePermission::Access
                || str_ends_with($permission->value, '.view'),
            )->toBeTrue("ReadOnly must not carry {$permission->value}");
        }
    });

    it('Auditor is audit + dashboard only', function (): void {
        expect(ConsoleRole::Auditor->permissions())->toBe([
            ConsolePermission::Access,
            ConsolePermission::DashboardView,
            ConsolePermission::AuditView,
        ]);
    });
});

describe('scope covering (global ⊇ server ⊇ database)', function (): void {
    it('wider covers narrower, never the reverse', function (): void {
        expect(ScopeType::Global->covers(ScopeType::Global))->toBeTrue()
            ->and(ScopeType::Global->covers(ScopeType::Server))->toBeTrue()
            ->and(ScopeType::Global->covers(ScopeType::Database))->toBeTrue()
            ->and(ScopeType::Server->covers(ScopeType::Server))->toBeTrue()
            ->and(ScopeType::Server->covers(ScopeType::Database))->toBeTrue()
            ->and(ScopeType::Server->covers(ScopeType::Global))->toBeFalse()
            ->and(ScopeType::Database->covers(ScopeType::Server))->toBeFalse()
            ->and(ScopeType::Database->covers(ScopeType::Global))->toBeFalse();
    });
});

it('Severity maps to PSR levels and alerts on warning + critical only', function (): void {
    expect(Severity::Info->psrLevel())->toBe('info')
        ->and(Severity::Critical->psrLevel())->toBe('critical')
        ->and(Severity::Info->alerts())->toBeFalse()
        ->and(Severity::Notice->alerts())->toBeFalse()
        ->and(Severity::Warning->alerts())->toBeTrue()
        ->and(Severity::Error->alerts())->toBeFalse()
        ->and(Severity::Critical->alerts())->toBeTrue();
});

it('OperationOutcome maps to HTTP statuses', function (): void {
    expect(OperationOutcome::Succeeded->httpStatus())->toBe(200)
        ->and(OperationOutcome::Failed->httpStatus())->toBe(500)
        ->and(OperationOutcome::RolledBack->httpStatus())->toBe(500);
});

it('only drops are destructive operation types', function (): void {
    $destructive = array_values(array_filter(
        OperationType::cases(),
        static fn (OperationType $t): bool => $t->isDestructive(),
    ));

    expect($destructive)->toBe([OperationType::DatabaseDrop, OperationType::AccountDrop]);
});

it('ExceptionCode is closed and collision-free', function (): void {
    $values = ExceptionCode::values();

    expect($values)->toBe(array_unique($values))
        ->and($values)->toHaveCount(17);
});
