<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Doctor\ConfigCheck;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\User;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
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

    it('no longer reads api.guard, and doctor says so', function (): void {
        config()->set('auth.guards.ops', ['driver' => 'session', 'provider' => 'users']);
        config()->set('laranail.db-console.api.auth');
        config()->set('laranail.db-console.api.guard', 'ops');
        mountDbConsoleApi();

        // The default guard (sanctum) applies; the retired key does nothing...
        $this->actingAs(apiCaller(), 'ops')->getJson('/api/db-console/servers')->assertUnauthorized();

        // ...and doctor names it instead of letting that pass silently.
        $finding = collect(app(ConfigCheck::class)->findings())->firstWhere('check', 'config:legacy-api-keys');
        expect($finding->isError())->toBeTrue()
            ->and($finding->message)->toContain('api.guard (now api.auth)');
    });

    it('mounts under api.path', function (): void {
        config()->set('laranail.db-console.api.path', 'ops/dbc');
        mountDbConsoleApi();

        $this->actingAs(apiCaller(), 'sanctum')->getJson('/ops/dbc/servers')->assertOk();
        $this->actingAs(apiCaller(), 'sanctum')->getJson('/api/db-console/servers')->assertNotFound();
    });

    it('no longer reads api.prefix, and doctor says so', function (): void {
        config()->set('laranail.db-console.api.path');
        config()->set('laranail.db-console.api.prefix', 'legacy/dbc');
        mountDbConsoleApi();

        $this->actingAs(apiCaller(), 'sanctum')->getJson('/api/db-console/servers')->assertOk();
        $this->actingAs(apiCaller(), 'sanctum')->getJson('/legacy/dbc/servers')->assertNotFound();

        $finding = collect(app(ConfigCheck::class)->findings())->firstWhere('check', 'config:legacy-api-keys');
        expect($finding->message)->toContain('api.prefix (now api.path)');
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

describe('api.auth_failure_limit', function (): void {
    it('answers 429 to an IP that keeps failing to authenticate, before looking at its credentials', function (): void {
        config()->set('laranail.db-console.api.auth_failure_limit', '3,1');
        mountDbConsoleApi();

        foreach (range(1, 3) as $ignored) {
            $this->getJson('/api/db-console/servers')->assertUnauthorized();
        }

        $this->getJson('/api/db-console/servers')->assertStatus(429)->assertHeader('Retry-After');
        // A valid caller from the same address waits out the window too.
        $this->actingAs(apiCaller(), 'sanctum')->getJson('/api/db-console/servers')->assertStatus(429);
    });

    it('counts per IP, so another address is unaffected', function (): void {
        config()->set('laranail.db-console.api.auth_failure_limit', '2,1');
        mountDbConsoleApi();

        $this->getJson('/api/db-console/servers')->assertUnauthorized();
        $this->getJson('/api/db-console/servers')->assertUnauthorized();
        $this->getJson('/api/db-console/servers')->assertStatus(429);

        $this->actingAs(apiCaller(), 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '::1'])
            ->getJson('/api/db-console/servers')
            ->assertOk();
    });

    it('does not count successful requests', function (): void {
        config()->set('laranail.db-console.api.auth_failure_limit', '1,1');
        mountDbConsoleApi();

        foreach (range(1, 4) as $ignored) {
            $this->actingAs(apiCaller(), 'sanctum')->getJson('/api/db-console/servers')->assertOk();
        }
    });

    it('can be switched off', function (): void {
        config()->set('laranail.db-console.api.auth_failure_limit', '');
        mountDbConsoleApi();

        foreach (range(1, 12) as $ignored) {
            $this->getJson('/api/db-console/servers')->assertUnauthorized();
        }
    });
});

describe('an account\'s grants', function (): void {
    it('lists them, structured', function (): void {
        mountDbConsoleApi();
        FakeProvisioningEngine::addAccount('shop');
        FakeProvisioningEngine::addGrant('shop', 'shop_prod');

        $this->actingAs(apiCaller(), 'sanctum')
            ->getJson('/api/db-console/servers/fake/accounts/shop/grants?host=%25')
            ->assertOk()
            ->assertJsonPath('data.0.line', 'shop_prod')
            ->assertJsonPath('data.0.readable', true);
    });

    it('is refused without account.view', function (): void {
        mountDbConsoleApi();
        $GLOBALS['apiDenied'] = [ConsolePermission::AccountView->ability()];

        $this->actingAs(apiCaller(), 'sanctum')
            ->getJson('/api/db-console/servers/fake/accounts/shop/grants')
            ->assertForbidden();
    });
});

describe('a client that does not send Accept: application/json', function (): void {
    it('still gets a refusal as a JSON body with its code', function (): void {
        mountDbConsoleApi();
        $GLOBALS['apiDenied'] = [ConsolePermission::DatabaseView->ability()];

        $this->actingAs(apiCaller(), 'sanctum')
            ->get('/api/db-console/servers/fake/databases')
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['code' => 'not_authorized']);
    });

    it('gets assurance_required with what is missing', function (): void {
        config()->set('laranail.db-console.security.assurance.enforce', 'require');
        mountDbConsoleApi();

        $this->actingAs(apiCaller(), 'sanctum')
            ->post('/api/db-console/servers/fake/databases', ['name' => 'shop_prod'])
            ->assertForbidden()
            ->assertJson(['code' => 'assurance_required', 'missing' => ['second_factor']]);
        expect(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(0);
    });

    it('gets validation errors as JSON, not a redirect', function (): void {
        mountDbConsoleApi();

        $this->actingAs(apiCaller(), 'sanctum')
            ->post('/api/db-console/servers/fake/databases', ['name' => 'shop_prod', 'on_existing' => 'sure'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('on_existing');
    });

    it('even when it asks for HTML', function (): void {
        mountDbConsoleApi();
        $GLOBALS['apiDenied'] = [ConsolePermission::DatabaseView->ability()];

        $this->actingAs(apiCaller(), 'sanctum')
            ->withHeaders(['Accept' => 'text/html'])
            ->get('/api/db-console/servers/fake/databases')
            ->assertForbidden()
            ->assertJson(['code' => 'not_authorized']);
    });
});
