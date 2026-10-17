<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeProvisioningEngine;

/*
 * PrivilegeManager::showGrants() had no caller: no command or route showed
 * what an account may do. The fake engine's grant read returns the database
 * names it recorded, one row each.
 */

beforeEach(function (): void {
    $this->migrateCatalog();
    $GLOBALS['cliDenied'] = [];
    Gate::before(fn ($user = null, string $ability = ''): bool => ! in_array($ability, $GLOBALS['cliDenied'], true));
    FakeProvisioningEngine::install();
    FakeProvisioningEngine::addAccount('shop');
    FakeProvisioningEngine::addGrant('shop', 'shop_prod');
});

it('prints an account\'s grants', function (): void {
    $this->artisan('laranail::db-console.user:grants', ['--server' => 'fake', '--user' => 'shop', '--host' => '%'])
        ->expectsOutputToContain('shop_prod')
        ->assertSuccessful();
});

it('prints them as JSON with --json', function (): void {
    $this->artisan('laranail::db-console.user:grants', ['--server' => 'fake', '--user' => 'shop', '--json' => true])
        ->expectsOutputToContain('"line": "shop_prod"')
        ->assertSuccessful();
});

it('needs --user', function (): void {
    $this->artisan('laranail::db-console.user:grants', ['--server' => 'fake', '--no-interaction' => true])
        ->assertFailed();
});

it('is refused to an operator who may not view accounts', function (): void {
    $GLOBALS['cliDenied'] = [ConsolePermission::AccountView->ability()];

    $this->artisan('laranail::db-console.user:grants', ['--server' => 'fake', '--user' => 'shop'])
        ->assertFailed();
});
