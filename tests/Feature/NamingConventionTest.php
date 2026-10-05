<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Contracts\Console\Kernel;
use Simtabi\Laranail\DBConsole\Models\Role;
use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Models\Permission;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\User;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\Package\Tools\Testing\NamingScope;
use Simtabi\Laranail\Package\Tools\Testing\NameRegistry;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Simtabi\Laranail\DBConsole\Authorization\DeprecatedAbilities;
use Simtabi\Laranail\Package\Tools\Testing\AssertsRegisteredNames;
use Simtabi\Laranail\Package\Tools\Commands\Concerns\SupportsNamespacedNames;

uses(AssertsRegisteredNames::class);

/*
 * Every public name db-console registers carries the vendor and slug, read from the live
 * registries of the booted application. The old bare names stay registered as deprecated
 * aliases, and each is listed here so a stale entry fails instead of covering a new name.
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

/**
 * @return list<string>
 */
function dbConsoleBareAbilities(): array
{
    return array_map(
        static fn (ConsolePermission $p): string => 'db-console.' . $p->value,
        ConsolePermission::cases(),
    );
}

/**
 * @return list<string> the E_USER_DEPRECATED messages raised while running $callback
 */
function dbConsoleDeprecations(Closure $callback): array
{
    $notices = [];
    set_error_handler(function (int $level, string $message) use (&$notices): bool {
        $notices[] = $message;

        return true;
    }, E_USER_DEPRECATED);

    try {
        $callback();
    } finally {
        restore_error_handler();
    }

    return $notices;
}

beforeEach(function (): void {
    DeprecatedAbilities::forgetWarnings();
});

it('scopes every gate ability, keeping the bare ones as deprecated aliases', function (): void {
    $scoped = $this->assertGateAbilitiesScoped(
        dbConsoleScope(),
        deprecated: dbConsoleBareAbilities(),
        atLeast: 21,
    );

    expect($scoped)->toHaveCount(21)
        ->toContain('laranail-db-console.access', 'laranail-db-console.database.drop');
});

it('scopes every command, keeping db-console:install as a deprecated alias', function (): void {
    $scoped = $this->assertCommandNamesScoped(
        dbConsoleScope(),
        deprecated: ['db-console:install'],
        atLeast: 34,
    );

    expect($scoped)->toContain('laranail::db-console.install', 'laranail::db-console.doctor');
});

it('scopes the middleware alias', function (): void {
    $this->assertRegisteredNamesScoped(NameRegistry::Middleware, dbConsoleScope());
});

function dbConsoleOperators(): array
{
    Schema::create('users', function ($table): void {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->timestamps();
    });
    config()->set('laranail.db-console.rbac.user_model', User::class);

    return [User::query()->create(['name' => 'owner']), User::query()->create(['name' => 'stranger'])];
}

it('answers a bare ability exactly as its scoped ability', function (): void {
    $this->migrateCatalog();
    app(RbacDriver::class)->seedDefaultRoles();
    [$owner, $stranger] = dbConsoleOperators();
    app(RbacDriver::class)->assign($owner, ConsoleRole::Owner->value, 'global');

    dbConsoleDeprecations(function () use ($owner, $stranger): void {
        foreach (ConsolePermission::cases() as $permission) {
            foreach ([$owner, $stranger] as $user) {
                foreach (['global', 'server:prod-mysql'] as $scope) {
                    expect(Gate::forUser($user)->allows($permission->deprecatedAbility(), $scope))
                        ->toBe(Gate::forUser($user)->allows($permission->ability(), $scope));
                }
            }
        }
    });

    expect(Gate::forUser($owner)->allows('db-console.database.drop', 'server:prod-mysql'))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('db-console.database.drop', 'server:prod-mysql'))->toBeFalse();
});

it('still resolves a custom role stored with pre-0.1 permission names', function (): void {
    $this->migrateCatalog();
    [$operator] = dbConsoleOperators();

    $legacy = Permission::query()->create(['name' => 'db-console.database.view']);
    $scoped = Permission::query()->create(['name' => 'laranail-db-console.audit.view']);
    Role::query()->create(['name' => 'legacy-viewer', 'label' => 'Legacy viewer', 'is_shipped' => false])
        ->permissions()->sync([$legacy->id, $scoped->id]);

    expect(app(RbacDriver::class)->permissionsForRole('legacy-viewer'))
        ->toEqualCanonicalizing([ConsolePermission::DatabaseView, ConsolePermission::AuditView]);

    app(RbacDriver::class)->assign($operator, 'legacy-viewer', 'global');

    expect(Gate::forUser($operator)->allows('laranail-db-console.database.view', 'global'))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('laranail-db-console.database.drop', 'global'))->toBeFalse();
});

it('reads a permission name in either form, and nothing else', function (): void {
    expect(ConsolePermission::fromAbility('laranail-db-console.database.drop'))->toBe(ConsolePermission::DatabaseDrop)
        ->and(ConsolePermission::fromAbility('db-console.database.drop'))->toBe(ConsolePermission::DatabaseDrop)
        ->and(ConsolePermission::fromAbility('database.drop'))->toBe(ConsolePermission::DatabaseDrop)
        ->and(ConsolePermission::fromAbility('other-package.database.drop'))->toBeNull();
});

it('delegates a bare ability through the gate, so a host override of the scoped one applies', function (): void {
    Gate::define('laranail-db-console.audit.view', static fn (?object $user = null): bool => true);

    dbConsoleDeprecations(function (): void {
        expect(Gate::allows('db-console.audit.view'))->toBeTrue()
            ->and(Gate::allows('db-console.audit.view', 'global'))->toBeTrue();
    });
});

it('announces a bare ability once per name', function (): void {
    $notices = dbConsoleDeprecations(function (): void {
        Gate::allows('db-console.access');
        Gate::allows('db-console.access');
        Gate::allows('db-console.audit.view');
        Gate::allows('laranail-db-console.access');
    });

    expect($notices)->toHaveCount(2)
        ->and($notices[0])->toContain('[db-console.access]')->toContain('[laranail-db-console.access]');
});

it('keeps db-console:install working, warning and forwarding to the scoped command', function (): void {
    // Stand in for the real install, which publishes into the host and probes servers.
    $canonical = new class extends Command
    {
        use SupportsNamespacedNames;

        protected $signature = 'laranail::db-console.install';

        public function handle(): int
        {
            $this->line('canonical install ran');

            return self::SUCCESS;
        }
    };
    app(Kernel::class)->registerCommand($canonical);

    $exit = Artisan::call('db-console:install');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('[db-console:install] is a deprecated alias')
        ->and($output)->toContain('[laranail::db-console.install]')
        ->and($output)->toContain('canonical install ran');
});
