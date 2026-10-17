<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\User;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Http\Api\DeprecatedConfigKeys;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeProvisioningEngine;

/*
 * The REST API end to end, against the fake provisioning server: the 409 an
 * existing resource gets, and the api.* keys config/db-console.php documents
 * (auth, path, require_https, confirm_destructive, rate_limit), which until
 * now were either never read or read under another name.
 */

/**
 * Mount the API routes as the provider would with api.enabled on. They read
 * their settings when loaded, so set config first.
 */
function mountDbConsoleApi(): void
{
    config()->set('laranail.db-console.api.enabled', true);
    require __DIR__ . '/../../../routes/api.php';
    app('router')->getRoutes()->refreshNameLookups();
}

function apiCaller(): User
{
    $user = new User;
    $user->id = 1;

    return $user;
}

beforeEach(function (): void {
    $this->migrateCatalog();
    DeprecatedConfigKeys::forgetWarnings();
    $GLOBALS['apiDenied'] = [];
    $GLOBALS['apiGateUsers'] = [];
    Gate::before(function ($user = null, string $ability = ''): bool {
        $GLOBALS['apiGateUsers'][] = $user?->getAuthIdentifier();

        return ! in_array($ability, $GLOBALS['apiDenied'], true);
    });
    FakeProvisioningEngine::install();
});

describe('an existing resource', function (): void {
    it('answers 409 with the options this caller may resubmit with', function (): void {
        mountDbConsoleApi();
        FakeProvisioningEngine::addAccount('shop');

        $this->actingAs(apiCaller(), 'sanctum')
            ->postJson('/api/db-console/servers/fake/accounts', ['username' => 'shop', 'host' => '%', 'generate' => true])
            ->assertStatus(409)
            ->assertJson(['code' => 'account.already_exists', 'options' => ['adopt', 'adopt_and_apply']]);
    });

    it('offers nothing to a caller who may not adopt', function (): void {
        mountDbConsoleApi();
        $GLOBALS['apiDenied'] = [ConsolePermission::ResourceAdopt->ability()];
        FakeProvisioningEngine::addDatabase('shop_prod');

        $this->actingAs(apiCaller(), 'sanctum')
            ->postJson('/api/db-console/servers/fake/databases', ['name' => 'shop_prod'])
            ->assertStatus(409)
            ->assertJson(['code' => 'database.already_exists', 'options' => []]);
    });

    it('adopts on resubmission with on_existing, answering 200', function (): void {
        mountDbConsoleApi();
        FakeProvisioningEngine::addAccount('shop');

        $this->actingAs(apiCaller(), 'sanctum')
            ->postJson('/api/db-console/servers/fake/accounts', ['username' => 'shop', 'host' => '%', 'generate' => true, 'on_existing' => 'adopt'])
            ->assertOk()
            ->assertJsonPath('already_existed', true)
            ->assertJsonPath('data.adopted', true)
            ->assertJsonPath('data.password_applied', false)
            ->assertJsonMissingPath('generated_password');
    });

    it('rejects an on_existing that is not a policy', function (): void {
        mountDbConsoleApi();

        $this->actingAs(apiCaller(), 'sanctum')
            ->postJson('/api/db-console/servers/fake/databases', ['name' => 'shop_prod', 'on_existing' => 'sure'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('on_existing');
    });

    it('still creates a new account with 201', function (): void {
        mountDbConsoleApi();

        $this->actingAs(apiCaller(), 'sanctum')
            ->postJson('/api/db-console/servers/fake/accounts', ['username' => 'fresh', 'host' => '%', 'generate' => true])
            ->assertCreated()
            ->assertJsonPath('data.adopted', false);
    });
});

describe('api.auth and api.path', function (): void {
    it('authenticates with the guard api.auth names', function (): void {
        config()->set('laranail.db-console.api.auth', 'web');
        mountDbConsoleApi();

        $this->actingAs(apiCaller(), 'sanctum')->getJson('/api/db-console/servers')->assertUnauthorized();
        $this->actingAs(apiCaller(), 'web')->getJson('/api/db-console/servers')->assertOk();
    });

    it('hands the services\' gate the API caller, not the default guard\'s nobody', function (): void {
        mountDbConsoleApi();
        // actingAs() makes the guard the default; a real request arrives on
        // the app's default guard (web) with a bearer token for sanctum.
        $this->actingAs(apiCaller(), 'sanctum');
        auth()->shouldUse('web');

        $this->getJson('/api/db-console/servers/fake/databases')->assertOk();

        expect($GLOBALS['apiGateUsers'])->not->toBeEmpty()->each->toBe(1);
    });

    it('falls back to api.guard when api.auth is unset, and says it is deprecated', function (): void {
        config()->set('laranail.db-console.api.auth');
        config()->set('laranail.db-console.api.guard', 'web');
        mountDbConsoleApi();

        $notices = [];
        set_error_handler(function (int $level, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $this->actingAs(apiCaller(), 'web')->getJson('/api/db-console/servers')->assertOk();
        } finally {
            restore_error_handler();
        }

        expect($notices)->toHaveCount(1)
            ->and($notices[0])->toContain('api.guard')->toContain('api.auth');
    });

    it('mounts under api.path', function (): void {
        config()->set('laranail.db-console.api.path', 'ops/dbc');
        mountDbConsoleApi();

        $this->actingAs(apiCaller(), 'sanctum')->getJson('/ops/dbc/servers')->assertOk();
        $this->actingAs(apiCaller(), 'sanctum')->getJson('/api/db-console/servers')->assertNotFound();
    });

    it('prefers api.path when the old api.prefix is set too', function (): void {
        config()->set('laranail.db-console.api.path', 'ops/dbc');
        config()->set('laranail.db-console.api.prefix', 'legacy/dbc');
        mountDbConsoleApi();

        $this->actingAs(apiCaller(), 'sanctum')->getJson('/ops/dbc/servers')->assertOk();
        $this->actingAs(apiCaller(), 'sanctum')->getJson('/legacy/dbc/servers')->assertNotFound();
    });

    it('falls back to api.prefix when api.path is unset', function (): void {
        config()->set('laranail.db-console.api.path');
        config()->set('laranail.db-console.api.prefix', 'legacy/dbc');
        set_error_handler(fn (): bool => true, E_USER_DEPRECATED);

        try {
            mountDbConsoleApi();
        } finally {
            restore_error_handler();
        }

        $this->actingAs(apiCaller(), 'sanctum')->getJson('/legacy/dbc/servers')->assertOk();
    });
});

describe('api.require_https', function (): void {
    it('refuses plain HTTP from a remote client by default', function (): void {
        mountDbConsoleApi();

        $this->actingAs(apiCaller(), 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->getJson('/api/db-console/servers')
            ->assertStatus(426);
    });

    it('lets plain HTTP through when switched off', function (): void {
        config()->set('laranail.db-console.api.require_https', false);
        mountDbConsoleApi();

        $this->actingAs(apiCaller(), 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->getJson('/api/db-console/servers')
            ->assertOk();
    });
});

describe('api.confirm_destructive', function (): void {
    it('demands the name in the body by default', function (): void {
        mountDbConsoleApi();
        FakeProvisioningEngine::addDatabase('shop_prod');

        $this->actingAs(apiCaller(), 'sanctum')
            ->deleteJson('/api/db-console/servers/fake/databases/shop_prod')
            ->assertStatus(422);
        expect(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(1);
    });

    it('drops without it when switched off', function (): void {
        config()->set('laranail.db-console.api.confirm_destructive', false);
        config()->set('laranail.db-console.backup.enabled', false);
        mountDbConsoleApi();
        FakeProvisioningEngine::addDatabase('shop_prod');

        $this->actingAs(apiCaller(), 'sanctum')
            ->deleteJson('/api/db-console/servers/fake/databases/shop_prod')
            ->assertOk();
        expect(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(0);
    });
});

describe('api.rate_limit', function (): void {
    it('answers 429 past the configured requests per window', function (): void {
        config()->set('laranail.db-console.api.rate_limit', '2,1');
        mountDbConsoleApi();
        $caller = apiCaller();

        $this->actingAs($caller, 'sanctum')->getJson('/api/db-console/servers')->assertOk();
        $this->actingAs($caller, 'sanctum')->getJson('/api/db-console/servers')->assertOk();
        $this->actingAs($caller, 'sanctum')->getJson('/api/db-console/servers')->assertStatus(429);
    });

    it('does not limit when switched off', function (): void {
        config()->set('laranail.db-console.api.rate_limit', '');
        mountDbConsoleApi();

        foreach (range(1, 5) as $ignored) {
            $this->actingAs(apiCaller(), 'sanctum')->getJson('/api/db-console/servers')->assertOk();
        }
    });
});
