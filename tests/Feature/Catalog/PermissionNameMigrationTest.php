<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Simtabi\Laranail\DBConsole\Models\Role;
use Spatie\Permission\Models\Role as SpatieRole;
use Simtabi\Laranail\DBConsole\Models\Permission;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\User;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Simtabi\Laranail\DBConsole\Access\Drivers\SpatieRbacDriver;

/*
 * The 0001_01_01_001100 migration replaces two runtime crutches: reading
 * permission rows stored under the pre-0.1 `db-console.*` names, and telling
 * existing installs to re-run install so Owner gets resource.adopt.
 */

function runPermissionNameMigration(): void
{
    (require dirname(__DIR__, 3) . '/database/migrations/0001_01_01_001100_migrate_db_console_permission_names.php')->up();
}

/**
 * @return list<string>
 */
function builtinPermissionsOf(string $role): array
{
    return Role::query()->where('name', $role)->firstOrFail()->permissions()->pluck('name')->sort()->values()->all();
}

describe('the builtin driver', function (): void {
    beforeEach(function (): void {
        $this->migrateCatalog();
    });

    it('renames an old-only row, and merges one install already seeded beside it', function (): void {
        $oldOnly = Permission::query()->create(['name' => 'db-console.database.view']);
        $oldDup = Permission::query()->create(['name' => 'db-console.audit.view']);
        $scopedDup = Permission::query()->create(['name' => 'laranail-db-console.audit.view']);
        Role::query()->create(['name' => 'legacy-viewer', 'is_shipped' => false])->permissions()->sync([$oldOnly->id, $oldDup->id]);
        Role::query()->create(['name' => 'auditor-ish', 'is_shipped' => false])->permissions()->sync([$scopedDup->id]);

        runPermissionNameMigration();

        expect(Permission::query()->where('name', 'like', 'db-console.%')->count())->toBe(0)
            ->and(builtinPermissionsOf('legacy-viewer'))->toBe(['laranail-db-console.audit.view', 'laranail-db-console.database.view'])
            ->and(builtinPermissionsOf('auditor-ish'))->toBe(['laranail-db-console.audit.view'])
            ->and(app(RbacDriver::class)->permissionsForRole('legacy-viewer'))
            ->toEqualCanonicalizing([ConsolePermission::DatabaseView, ConsolePermission::AuditView]);
    });

    it('gives the shipped Owner resource.adopt, and nothing to any other role', function (): void {
        app(RbacDriver::class)->seedDefaultRoles();
        $adopt = Permission::query()->where('name', ConsolePermission::ResourceAdopt->ability())->firstOrFail();
        Role::query()->where('name', 'owner')->firstOrFail()->permissions()->detach($adopt->id);
        $adminBefore = builtinPermissionsOf('admin');

        runPermissionNameMigration();

        expect(builtinPermissionsOf('owner'))->toContain(ConsolePermission::ResourceAdopt->ability())
            ->and(builtinPermissionsOf('admin'))->toBe($adminBefore);
    });

    it('creates the resource.adopt row on an install seeded before it existed', function (): void {
        app(RbacDriver::class)->seedDefaultRoles();
        $adopt = Permission::query()->where('name', ConsolePermission::ResourceAdopt->ability())->firstOrFail();
        Role::query()->where('name', 'owner')->firstOrFail()->permissions()->detach($adopt->id);
        $adopt->delete();

        runPermissionNameMigration();

        expect(builtinPermissionsOf('owner'))->toContain(ConsolePermission::ResourceAdopt->ability());
    });

    it('leaves a custom role called owner alone', function (): void {
        Role::query()->create(['name' => 'owner', 'is_shipped' => false]);

        runPermissionNameMigration();

        expect(builtinPermissionsOf('owner'))->toBe([]);
    });

    it('is idempotent, and does nothing on a catalog with no roles', function (): void {
        runPermissionNameMigration();
        runPermissionNameMigration();

        expect(Permission::query()->count())->toBe(0);

        Permission::query()->create(['name' => 'db-console.access']);
        runPermissionNameMigration();
        runPermissionNameMigration();

        expect(Permission::query()->pluck('name')->all())->toBe(['laranail-db-console.access']);
    });

    it('lets an operator holding a renamed permission through the gate', function (): void {
        Schema::create('users', function ($table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        config()->set('laranail.db-console.rbac.user_model', User::class);
        $operator = User::query()->create(['name' => 'viewer']);
        $old = Permission::query()->create(['name' => 'db-console.database.view']);
        Role::query()->create(['name' => 'legacy-viewer', 'is_shipped' => false])->permissions()->sync([$old->id]);
        app(RbacDriver::class)->assign($operator, 'legacy-viewer', 'global');

        expect(Gate::forUser($operator)->allows('laranail-db-console.database.view', 'global'))->toBeFalse();

        runPermissionNameMigration();

        expect(Gate::forUser($operator)->allows('laranail-db-console.database.view', 'global'))->toBeTrue();
    });
});

describe('the spatie driver', function (): void {
    beforeEach(function (): void {
        $this->migrateCatalog();
        config()->set('laranail.db-console.rbac.driver', 'spatie');
        foreach (['permissions', 'roles'] as $name) {
            Schema::create($name, function ($table): void {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }
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
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    });

    it('renames and merges old names, and gives Owner resource.adopt', function (): void {
        SpatiePermission::findOrCreate('db-console.database.view');
        SpatiePermission::findOrCreate('db-console.audit.view');
        SpatiePermission::findOrCreate('laranail-db-console.audit.view');
        SpatieRole::findOrCreate('legacy-viewer')->givePermissionTo(['db-console.database.view', 'db-console.audit.view']);
        SpatieRole::findOrCreate('owner');

        runPermissionNameMigration();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect(SpatiePermission::query()->where('name', 'like', 'db-console.%')->count())->toBe(0)
            ->and(app(SpatieRbacDriver::class)->permissionsForRole('legacy-viewer'))
            ->toEqualCanonicalizing([ConsolePermission::DatabaseView, ConsolePermission::AuditView])
            ->and(app(SpatieRbacDriver::class)->permissionsForRole('owner'))->toBe([ConsolePermission::ResourceAdopt]);
    });
});
