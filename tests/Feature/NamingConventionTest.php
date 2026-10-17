<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\Package\Tools\Testing\NamingScope;
use Simtabi\Laranail\Package\Tools\Testing\NameRegistry;
use Simtabi\Laranail\Package\Tools\Testing\AssertsRegisteredNames;

uses(AssertsRegisteredNames::class);

/*
 * Every public name db-console registers carries the vendor and slug, read from the live
 * registries of the booted application. The bare aliases kept through 0.1.1 (the
 * db-console.<permission> abilities and db-console:install) are gone, and these tests say so,
 * so one cannot quietly come back.
 */

function dbConsoleScope(): NamingScope
{
    return NamingScope::for(
        'laranail/db-console',
        'Simtabi\\Laranail\\DBConsole\\',
        // src/, not the package root: in the package's own suite the root also holds vendor/ and
        // tests/, so every framework closure would read as this package's.
        basePath: dirname(__DIR__, 2) . '/src',
    );
}

it('scopes every gate ability, and defines no bare one', function (): void {
    $scoped = $this->assertGateAbilitiesScoped(dbConsoleScope(), deprecated: [], atLeast: 22);

    expect($scoped)->toHaveCount(22)
        ->toContain('laranail-db-console.access', 'laranail-db-console.database.drop');

    foreach (ConsolePermission::cases() as $permission) {
        expect(Gate::has('db-console.' . $permission->value))->toBeFalse();
    }
});

it('scopes every command, and registers no bare install alias', function (): void {
    $scoped = $this->assertCommandNamesScoped(dbConsoleScope(), deprecated: [], atLeast: 34);

    expect($scoped)->toContain('laranail::db-console.install', 'laranail::db-console.doctor')
        ->and(array_keys(Artisan::all()))->not->toContain('db-console:install');
});

it('scopes the middleware alias', function (): void {
    $this->assertRegisteredNamesScoped(NameRegistry::Middleware, dbConsoleScope());
});

it('reads a scoped permission name or a bare permission value, and nothing else', function (): void {
    expect(ConsolePermission::fromAbility('laranail-db-console.database.drop'))->toBe(ConsolePermission::DatabaseDrop)
        ->and(ConsolePermission::fromAbility('database.drop'))->toBe(ConsolePermission::DatabaseDrop)
        ->and(ConsolePermission::fromAbility('db-console.database.drop'))->toBeNull()
        ->and(ConsolePermission::fromAbility('other-package.database.drop'))->toBeNull();
});
