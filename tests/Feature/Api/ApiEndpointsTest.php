<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\User;
use Simtabi\Laranail\DBConsole\Models\RoleAssignment;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Access\Contracts\AccessManager;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeProvisioningEngine;

/*
 * The endpoints added for the request classes that had no route (account
 * host and password, revoke, attach and detach, tokens, role assignment) and
 * the drop endpoints now validated by DropDatabaseRequest/DropAccountRequest.
 */

function mountEndpointsApi(): void
{
    config()->set('laranail.db-console.api.enabled', true);
    require __DIR__ . '/../../../routes/api.php';
    app('router')->getRoutes()->refreshNameLookups();
}

function endpointsCaller(): User
{
    $user = new User;
    $user->id = 1;

    return $user;
}

function persistedApiUser(string $name = 'op'): User
{
    return User::query()->create(['name' => $name]);
}

/**
 * An AccessManager granting each user id exactly the listed permissions, so
 * the token cap can be seen to take the caller's and the holder's into account.
 *
 * @param array<int|string, list<ConsolePermission>> $byUser
 */
function accessByUser(array $byUser): AccessManager
{
    return new readonly class($byUser) implements AccessManager
    {
        /** @param array<int|string, list<ConsolePermission>> $byUser */
        public function __construct(private array $byUser) {}

        public function allows(?Authenticatable $user, ConsolePermission $permission, ?string $scope): bool
        {
            return in_array($permission, $this->byUser[$user?->getAuthIdentifier()] ?? [], true);
        }

        public function authorize(?Authenticatable $user, ConsolePermission $permission, ?string $scope): void {}
    };
}

beforeEach(function (): void {
    $this->migrateCatalog();
    $GLOBALS['apiDenied'] = [];
    Gate::before(fn ($user = null, string $ability = ''): bool => ! in_array($ability, $GLOBALS['apiDenied'], true));
    FakeProvisioningEngine::install();
    config()->set('laranail.db-console.backup.enabled', false);
    config()->set('laranail.db-console.rbac.user_model', User::class);

    Schema::create('users', function ($table): void {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->timestamps();
    });
    Schema::create('personal_access_tokens', function ($table): void {
        $table->increments('id');
        $table->morphs('tokenable');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
});

describe('account password', function (): void {
    it('sets a generated password and returns it once', function (): void {
        mountEndpointsApi();
        FakeProvisioningEngine::addAccount('shop');
        $before = FakeProvisioningEngine::passwordOf('shop');

        $response = $this->actingAs(endpointsCaller(), 'sanctum')
            ->postJson('/api/db-console/servers/fake/accounts/shop/password', ['host' => '%', 'generate' => true])
            ->assertOk();

        expect($response->json('generated_password'))->toBeString()
            ->and(FakeProvisioningEngine::passwordOf('shop'))->not->toBe($before);
    });

    it('refuses a request with neither a password nor generate', function (): void {
        mountEndpointsApi();
        FakeProvisioningEngine::addAccount('shop');

        $this->actingAs(endpointsCaller(), 'sanctum')
            ->postJson('/api/db-console/servers/fake/accounts/shop/password', ['host' => '%'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    it('is refused without account.rotate', function (): void {
        mountEndpointsApi();
        $GLOBALS['apiDenied'] = [ConsolePermission::AccountRotate->ability()];
        FakeProvisioningEngine::addAccount('shop');

        $this->actingAs(endpointsCaller(), 'sanctum')
            ->postJson('/api/db-console/servers/fake/accounts/shop/password', ['host' => '%', 'generate' => true])
            ->assertForbidden();
    });
});

describe('account host', function (): void {
    it('validates the move before touching the server', function (): void {
        mountEndpointsApi();

        $this->actingAs(endpointsCaller(), 'sanctum')
            ->patchJson('/api/db-console/servers/fake/accounts/shop', ['host' => '%', 'new_host' => '%'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_host']);
    });
});

describe('revoke', function (): void {
    it('revokes an account\'s privileges on a database', function (): void {
        mountEndpointsApi();
        FakeProvisioningEngine::addAccount('shop');
        FakeProvisioningEngine::addGrant('shop', 'shop_prod');

        $this->actingAs(endpointsCaller(), 'sanctum')
            ->deleteJson('/api/db-console/servers/fake/grants', ['username' => 'shop', 'host' => '%', 'database' => 'shop_prod'])
            ->assertOk();

        expect(FakeProvisioningEngine::count('fake_grants', 'shop'))->toBe(0);
    });

    it('is refused without grant.revoke', function (): void {
        mountEndpointsApi();
        $GLOBALS['apiDenied'] = [ConsolePermission::GrantRevoke->ability()];
        FakeProvisioningEngine::addGrant('shop', 'shop_prod');

        $this->actingAs(endpointsCaller(), 'sanctum')
            ->deleteJson('/api/db-console/servers/fake/grants', ['username' => 'shop', 'host' => '%', 'database' => 'shop_prod'])
            ->assertForbidden();
        expect(FakeProvisioningEngine::count('fake_grants', 'shop'))->toBe(1);
    });
});

describe('attach and detach', function (): void {
    it('attaches every user to every database and reports each pairing', function (): void {
        mountEndpointsApi();
        FakeProvisioningEngine::addAccount('shop');
        FakeProvisioningEngine::addAccount('reporting');

        $this->actingAs(endpointsCaller(), 'sanctum')
            ->postJson('/api/db-console/servers/fake/attachments', [
                'users'     => [['username' => 'shop', 'host' => '%'], ['username' => 'reporting', 'host' => '%']],
                'databases' => ['shop_prod', 'shop_audit'],
                'preset'    => 'read_only',
            ])
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonCount(0, 'data.failed');

        expect(FakeProvisioningEngine::count('fake_grants', 'shop'))->toBe(2)
            ->and(FakeProvisioningEngine::count('fake_grants', 'reporting'))->toBe(2);
    });

    it('detaches them again', function (): void {
        mountEndpointsApi();
        FakeProvisioningEngine::addGrant('shop', 'shop_prod');
        FakeProvisioningEngine::addGrant('shop', 'shop_audit');

        $this->actingAs(endpointsCaller(), 'sanctum')
            ->deleteJson('/api/db-console/servers/fake/attachments', [
                'users'     => [['username' => 'shop', 'host' => '%']],
                'databases' => ['shop_prod', 'shop_audit'],
            ])
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        expect(FakeProvisioningEngine::count('fake_grants', 'shop'))->toBe(0);
    });
});

describe('the drop endpoints are validated', function (): void {
    it('requires the host when dropping an account, instead of assuming %', function (): void {
        mountEndpointsApi();
        FakeProvisioningEngine::addAccount('shop');

        $this->actingAs(endpointsCaller(), 'sanctum')
            ->deleteJson('/api/db-console/servers/fake/accounts/shop', ['confirm' => 'shop'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host']);
        expect(FakeProvisioningEngine::count('fake_accounts', 'shop'))->toBe(1);
    });

    it('drops an account with its host and confirmation', function (): void {
        mountEndpointsApi();
        FakeProvisioningEngine::addAccount('shop');

        $this->actingAs(endpointsCaller(), 'sanctum')
            ->deleteJson('/api/db-console/servers/fake/accounts/shop', ['host' => '%', 'confirm' => 'shop'])
            ->assertOk();
        expect(FakeProvisioningEngine::count('fake_accounts', 'shop'))->toBe(0);
    });

    it('reports a wrong confirmation as a validation error on confirm', function (): void {
        mountEndpointsApi();
        FakeProvisioningEngine::addDatabase('shop_prod');

        $this->actingAs(endpointsCaller(), 'sanctum')
            ->deleteJson('/api/db-console/servers/fake/databases/shop_prod', ['confirm' => 'shop_staging'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm']);
        expect(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(1);
    });

    it('validates the database name from the route', function (): void {
        mountEndpointsApi();

        $this->actingAs(endpointsCaller(), 'sanctum')
            ->deleteJson('/api/db-console/servers/fake/databases/bad-name!', ['confirm' => 'bad-name!'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });
});

describe('tokens', function (): void {
    it('is refused without token.manage', function (): void {
        mountEndpointsApi();
        $GLOBALS['apiDenied'] = [ConsolePermission::TokenManage->ability()];
        $holder = persistedApiUser('ci');

        $this->actingAs(persistedApiUser(), 'sanctum')
            ->postJson('/api/db-console/tokens', ['user_id' => $holder->id, 'name' => 'ci'])
            ->assertForbidden();
    });

    it('caps a token by both the caller and the holder', function (): void {
        mountEndpointsApi();
        $caller = persistedApiUser('admin');
        $holder = persistedApiUser('owner');
        $this->app->instance(AccessManager::class, accessByUser([
            $caller->id => [ConsolePermission::TokenManage, ConsolePermission::DatabaseView],
            $holder->id => [ConsolePermission::DatabaseView, ConsolePermission::SecretsManage],
        ]));

        // The holder may manage secrets, but the caller may not hand that out.
        $this->actingAs($caller, 'sanctum')
            ->postJson('/api/db-console/tokens', ['user_id' => $holder->id, 'name' => 'ci', 'abilities' => [ConsolePermission::SecretsManage->value]])
            ->assertForbidden();

        $this->actingAs($caller, 'sanctum')
            ->postJson('/api/db-console/tokens', ['user_id' => $holder->id, 'name' => 'ci'])
            ->assertCreated()
            ->assertJsonPath('data.abilities', [ConsolePermission::DatabaseView->value]);
    });

    it('answers 404 for an unknown user', function (): void {
        mountEndpointsApi();

        $this->actingAs(persistedApiUser(), 'sanctum')
            ->postJson('/api/db-console/tokens', ['user_id' => 999, 'name' => 'ci'])
            ->assertNotFound();
    });
});

describe('role assignment', function (): void {
    it('is refused without settings.manage', function (): void {
        mountEndpointsApi();
        $GLOBALS['apiDenied'] = [ConsolePermission::SettingsManage->ability()];
        $assignee = persistedApiUser('ops');

        $this->actingAs(persistedApiUser(), 'sanctum')
            ->postJson('/api/db-console/role-assignments', ['user_id' => $assignee->id, 'role' => 'operator', 'scope' => 'global'])
            ->assertForbidden();
        expect(RoleAssignment::query()->count())->toBe(0);
    });

    it('assigns a known role at a scope', function (): void {
        mountEndpointsApi();
        $assignee = persistedApiUser('ops');

        $this->actingAs(persistedApiUser(), 'sanctum')
            ->postJson('/api/db-console/role-assignments', ['user_id' => $assignee->id, 'role' => 'operator', 'scope' => 'server:fake'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'operator');
        expect(RoleAssignment::query()->where('role', 'operator')->count())->toBe(1);
    });

    it('refuses a role that grants nothing rather than storing it', function (): void {
        mountEndpointsApi();
        $assignee = persistedApiUser('ops');

        $this->actingAs(persistedApiUser(), 'sanctum')
            ->postJson('/api/db-console/role-assignments', ['user_id' => $assignee->id, 'role' => 'superuser', 'scope' => 'global'])
            ->assertStatus(422);
        expect(RoleAssignment::query()->count())->toBe(0);
    });
});

describe('the read endpoints are gated', function (): void {
    it('lists only the servers the caller may see', function (): void {
        mountEndpointsApi();
        $caller = endpointsCaller();

        expect(array_column($this->actingAs($caller, 'sanctum')->getJson('/api/db-console/servers')->assertOk()->json('data'), 'name'))
            ->toContain('fake');

        $GLOBALS['apiDenied'] = [ConsolePermission::ServerView->ability()];

        expect($this->actingAs($caller, 'sanctum')->getJson('/api/db-console/servers')->assertOk()->json('data'))->toBe([]);
    });

    it('refuses the audit trail without audit.view', function (): void {
        mountEndpointsApi();
        $GLOBALS['apiDenied'] = [ConsolePermission::AuditView->ability()];

        $this->actingAs(endpointsCaller(), 'sanctum')->getJson('/api/db-console/audit')->assertForbidden();
    });

    it('refuses the webhook list without webhook.manage', function (): void {
        mountEndpointsApi();
        $GLOBALS['apiDenied'] = [ConsolePermission::WebhookManage->ability()];

        $this->actingAs(endpointsCaller(), 'sanctum')->getJson('/api/db-console/webhooks')->assertForbidden();
    });
});
