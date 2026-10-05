<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\DBConsole\Models\Role;
use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;

beforeEach(function (): void {
    $this->migrateCatalog();
});

it('registers the install command', function (): void {
    // The scoped name, plus the pre-0.1 name kept as a deprecated forwarder.
    expect(array_keys(Artisan::all()))->toContain('laranail::db-console.install', 'db-console:install');
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
