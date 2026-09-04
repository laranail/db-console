<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\User;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\RbacAccessManager;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Simtabi\Laranail\DBConsole\Access\Drivers\SpatieRbacDriver;
use Simtabi\Laranail\DBConsole\Access\Drivers\BuiltinRbacDriver;

/**
 * Build a driver by name, wiring the storage each needs. Both share
 * DBConsole's role_assignments table (scope); builtin also uses our
 * role/permission tables, Spatie uses its own.
 */
function makeDriver(string $which): RbacDriver
{
    if ($which === 'spatie') {
        // Spatie's permission tables on the default connection.
        loadSpatieTables();

        return app(SpatieRbacDriver::class);
    }

    return app(BuiltinRbacDriver::class);
}

function loadSpatieTables(): void
{
    Schema::create('permissions', function ($table): void {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });
    Schema::create('roles', function ($table): void {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });
    Schema::create('model_has_permissions', function ($table): void {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['permission_id', 'model_id', 'model_type']);
    });
    Schema::create('model_has_roles', function ($table): void {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['role_id', 'model_id', 'model_type']);
    });
    Schema::create('role_has_permissions', function ($table): void {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
        $table->primary(['permission_id', 'role_id']);
    });
}

beforeEach(function (): void {
    $this->migrateCatalog();
    Schema::create('users', function ($table): void {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->timestamps();
    });
    config()->set('laranail.db-console.rbac.user_model', User::class);
});

function operator(): User
{
    return User::query()->create(['name' => 'op']);
}

function accessFor(RbacDriver $driver): RbacAccessManager
{
    return new RbacAccessManager($driver);
}

dataset('drivers', ['builtin', 'spatie']);

describe('seeded roles compose the shipped permission sets', function (): void {
    it('seeds the shipped roles with exactly their permissions', function (string $which): void {
        $driver = makeDriver($which);
        $driver->seedDefaultRoles();

        expect($driver->permissionsForRole(ConsoleRole::Owner->value))
            ->toEqualCanonicalizing(ConsoleRole::Owner->permissions())
            ->and($driver->permissionsForRole(ConsoleRole::ReadOnly->value))
            ->toEqualCanonicalizing(ConsoleRole::ReadOnly->permissions())
            ->and($driver->driver())->toBe($which);
    })->with('drivers');
});

describe('scope-aware allow/deny (section 20 resolution flow)', function (): void {
    it('allows an operation only within an assignment that grants it AND covers the scope', function (string $which): void {
        $driver = makeDriver($which);
        $driver->seedDefaultRoles();
        $access = accessFor($driver);

        $alice = operator();
        // Alice: Admin on prod-mysql, ReadOnly on prod-postgres (scenario B/C).
        $driver->assign($alice, ConsoleRole::Admin->value, 'server:prod-mysql');
        $driver->assign($alice, ConsoleRole::ReadOnly->value, 'server:prod-postgres');

        // Admin on prod-mysql → can create databases there.
        expect($access->allows($alice, ConsolePermission::DatabaseCreate, 'server:prod-mysql'))->toBeTrue()
            // ...and on a specific database on that server (server ⊇ database).
            ->and($access->allows($alice, ConsolePermission::DatabaseCreate, 'database:prod-mysql/shop_prod'))->toBeTrue()
            // ReadOnly on prod-postgres → can view but not create.
            ->and($access->allows($alice, ConsolePermission::DatabaseView, 'server:prod-postgres'))->toBeTrue()
            ->and($access->allows($alice, ConsolePermission::DatabaseCreate, 'server:prod-postgres'))->toBeFalse()
            // No assignment on staging → denied entirely.
            ->and($access->allows($alice, ConsolePermission::DatabaseView, 'server:staging'))->toBeFalse();
    })->with('drivers');

    it('a database-scoped operator is denied on other databases (matrix)', function (string $which): void {
        $driver = makeDriver($which);
        $driver->seedDefaultRoles();
        $access = accessFor($driver);

        $bob = operator();
        // Bob operates only shop_ databases on prod-mysql.
        $driver->assign($bob, ConsoleRole::Operator->value, 'database:prod-mysql/shop_*');

        expect($access->allows($bob, ConsolePermission::GrantCreate, 'database:prod-mysql/shop_prod'))->toBeTrue()
            ->and($access->allows($bob, ConsolePermission::GrantCreate, 'database:prod-mysql/other_db'))->toBeFalse()
            // A database scope never covers the whole server.
            ->and($access->allows($bob, ConsolePermission::DatabaseCreate, 'server:prod-mysql'))->toBeFalse();
    })->with('drivers');

    it('deny-by-default: an operator with no assignment is denied everything', function (string $which): void {
        $driver = makeDriver($which);
        $driver->seedDefaultRoles();
        $access = accessFor($driver);

        $nobody = operator();

        foreach (ConsolePermission::cases() as $permission) {
            expect($access->allows($nobody, $permission, 'server:prod-mysql'))->toBeFalse();
        }
    })->with('drivers');
});

describe('no privilege escalation (must never be weakened)', function (): void {
    it('an operator can never gain a permission their roles do not include', function (string $which): void {
        $driver = makeDriver($which);
        $driver->seedDefaultRoles();
        $access = accessFor($driver);

        $operator = operator();
        // Operator role: no drops, no secrets, no settings.
        $driver->assign($operator, ConsoleRole::Operator->value, 'server:prod-mysql');

        expect($access->allows($operator, ConsolePermission::DatabaseDrop, 'server:prod-mysql'))->toBeFalse()
            ->and($access->allows($operator, ConsolePermission::AccountDrop, 'server:prod-mysql'))->toBeFalse()
            ->and($access->allows($operator, ConsolePermission::SecretsManage, 'server:prod-mysql'))->toBeFalse()
            ->and($access->allows($operator, ConsolePermission::SettingsManage, 'server:prod-mysql'))->toBeFalse();
    })->with('drivers');

    it('an operator can never widen their own scope', function (string $which): void {
        $driver = makeDriver($which);
        $driver->seedDefaultRoles();
        $access = accessFor($driver);

        $operator = operator();
        // Admin, but only on prod-mysql.
        $driver->assign($operator, ConsoleRole::Admin->value, 'server:prod-mysql');

        // Cannot reach prod-postgres, staging, or global — no matter the permission.
        expect($access->allows($operator, ConsolePermission::DatabaseCreate, 'server:prod-postgres'))->toBeFalse()
            ->and($access->allows($operator, ConsolePermission::DatabaseView, 'server:staging'))->toBeFalse()
            ->and($access->allows($operator, ConsolePermission::DatabaseView, null))->toBeFalse();  // global target
    })->with('drivers');
});

describe('both drivers produce identical verdicts for the same assignments', function (): void {
    it('agrees on a battery of (permission, scope) checks', function (): void {
        $builtin = makeDriver('builtin');
        $spatie = makeDriver('spatie');
        $builtin->seedDefaultRoles();
        $spatie->seedDefaultRoles();

        $userB = operator();
        $userS = User::query()->create(['name' => 'op2']);

        $builtin->assign($userB, ConsoleRole::Admin->value, 'server:prod-mysql');
        $builtin->assign($userB, ConsoleRole::ReadOnly->value, 'database:prod-postgres/analytics');
        $spatie->assign($userS, ConsoleRole::Admin->value, 'server:prod-mysql');
        $spatie->assign($userS, ConsoleRole::ReadOnly->value, 'database:prod-postgres/analytics');

        $accessB = accessFor($builtin);
        $accessS = accessFor($spatie);

        $checks = [
            [ConsolePermission::DatabaseCreate, 'server:prod-mysql'],
            [ConsolePermission::DatabaseDrop, 'server:prod-mysql'],
            [ConsolePermission::SecretsManage, 'server:prod-mysql'],
            [ConsolePermission::DatabaseView, 'database:prod-postgres/analytics'],
            [ConsolePermission::DatabaseView, 'database:prod-postgres/other'],
            [ConsolePermission::DatabaseCreate, 'server:staging'],
            [ConsolePermission::GrantCreate, 'database:prod-mysql/shop_prod'],
        ];

        foreach ($checks as [$permission, $scope]) {
            expect($accessB->allows($userB, $permission, $scope))
                ->toBe($accessS->allows($userS, $permission, $scope), "disagreement on {$permission->value} @ {$scope}");
        }
    });
});
