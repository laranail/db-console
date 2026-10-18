<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\DBConsole\Models\Role;
use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\DefaultRoleSeeder;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;

beforeEach(function (): void {
    $this->migrateCatalog();
});

it('registers the install command', function (): void {
    // The scoped name, plus the pre-0.1 name kept as a deprecated forwarder.
    expect(array_keys(Artisan::all()))->toContain('laranail::db-console.install')
        ->not->toContain('db-console:install');
});

it('seeds the shipped console roles (the install seed step, run directly)', function (): void {
    // Exercise the seed step's effect directly (the full install command also
    // publishes + migrates, which testbench handles separately).
    app(RbacDriver::class)->seedDefaultRoles();

    foreach (ConsoleRole::cases() as $role) {
        expect(Role::query()->where('name', $role->value)->where('is_shipped', true)->exists())
            ->toBeTrue("role {$role->value} should be seeded");
    }

    // Owner composes to every permission; ReadOnly to views only.
    expect(app(RbacDriver::class)->permissionsForRole(ConsoleRole::Owner->value))
        ->toHaveCount(count(ConsolePermission::cases()));
});

describe('rbac.seed_default_roles', function (): void {
    it('seeds the shipped roles at install when true (the default)', function (): void {
        expect(config('laranail.db-console.rbac.seed_default_roles'))->toBeTrue()
            ->and(app(DefaultRoleSeeder::class)->seedIfEnabled())->toBeTrue()
            ->and(Role::query()->where('is_shipped', true)->count())->toBe(count(ConsoleRole::cases()));
    });

    it('leaves the roles unseeded at install when false', function (): void {
        config()->set('laranail.db-console.rbac.seed_default_roles', false);

        expect(app(DefaultRoleSeeder::class)->seedIfEnabled())->toBeFalse()
            ->and(Role::query()->count())->toBe(0);
    });

    it('is the step the install command runs', function (): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Providers/DBConsoleServiceProvider.php');

        expect($source)->toContain('DefaultRoleSeeder::class)->seedIfEnabled()')
            ->not->toContain('RbacDriver::class)->seedDefaultRoles()');
    });
});
