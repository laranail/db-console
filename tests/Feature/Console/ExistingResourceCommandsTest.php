<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\User;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeProvisioningEngine;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FixedOperatorAssurance;

/*
 * The CLI end of the existing-resource policy: --on-existing, a prompt naming
 * each option's risk when interactive, and the configured policy under
 * --no-interaction.
 */

beforeEach(function (): void {
    $this->migrateCatalog();
    $GLOBALS['cliDenied'] = [];
    Gate::before(fn ($user = null, string $ability = ''): bool => ! in_array($ability, $GLOBALS['cliDenied'], true));
    FakeProvisioningEngine::install();
});

function policyChoices(array $policies): array
{
    return array_map(static fn (ExistingResourcePolicy $p): string => (string) $p->description(), $policies);
}

describe('user:create', function (): void {
    it('refuses an existing account under --no-interaction, and says how to adopt it', function (): void {
        FakeProvisioningEngine::addAccount('shop');

        $this->artisan('laranail::db-console.user:create', ['--server' => 'fake', '--user' => 'shop', '--host' => '%', '--generate' => true, '--no-interaction' => true])
            ->expectsOutputToContain('already exists')
            ->expectsOutputToContain('--on-existing=adopt')
            ->assertFailed();
    });

    it('does not suggest adopting to an operator who may not', function (): void {
        $GLOBALS['cliDenied'] = [ConsolePermission::ResourceAdopt->ability()];
        FakeProvisioningEngine::addAccount('shop');

        $this->artisan('laranail::db-console.user:create', ['--server' => 'fake', '--user' => 'shop', '--host' => '%', '--generate' => true, '--no-interaction' => true])
            ->doesntExpectOutputToContain('--on-existing')
            ->assertFailed();
    });

    it('adopts with --on-existing=adopt, keeping the password', function (): void {
        FakeProvisioningEngine::addAccount('shop', '%', 'theirs');

        $this->artisan('laranail::db-console.user:create', ['--server' => 'fake', '--user' => 'shop', '--host' => '%', '--generate' => true, '--on-existing' => 'adopt'])
            ->expectsOutputToContain('adopted as it was, password unchanged')
            ->doesntExpectOutputToContain('shown once')
            ->assertSuccessful();

        expect(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', 'theirs'));
    });

    it('rejects an --on-existing that is not a policy', function (): void {
        $this->artisan('laranail::db-console.user:create', ['--server' => 'fake', '--user' => 'shop', '--on-existing' => 'yes'])
            ->expectsOutputToContain('--on-existing must be one of: refuse, adopt, adopt_and_apply')
            ->assertFailed();
    });

    it('asks, naming each option\'s risk, when interactive and the account exists', function (): void {
        FakeProvisioningEngine::addAccount('shop', '%', 'theirs');
        $password = Password::generate()->reveal();
        $operator = new User;
        $operator->id = 7;
        $this->actingAs($operator);
        app()->instance(OperatorAssurance::class, FixedOperatorAssurance::confirmedNow());

        $this->artisan('laranail::db-console.user:create', ['--server' => 'fake', '--user' => 'shop', '--host' => '%', '--password' => $password])
            ->expectsChoice(
                "Account 'shop'@'%' already exists. What should happen to it?",
                'adopt_and_apply',
                ['refuse', 'adopt', 'adopt_and_apply', ...policyChoices(ExistingResourcePolicy::cases())],
            )
            ->expectsOutputToContain('its password replaced')
            ->assertSuccessful();

        expect(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', $password));
    });

    it('does not ask about an account that is not there', function (): void {
        $this->artisan('laranail::db-console.user:create', ['--server' => 'fake', '--user' => 'fresh', '--host' => '%', '--generate' => true])
            ->expectsOutputToContain('created')
            ->assertSuccessful();
    });
});

describe('db:create', function (): void {
    it('offers only refuse and adopt for a database', function (): void {
        FakeProvisioningEngine::addDatabase('shop_prod');

        $this->artisan('laranail::db-console.db:create', ['--server' => 'fake', '--name' => 'shop_prod'])
            ->expectsChoice(
                "Database 'shop_prod' already exists. What should happen to it?",
                'refuse',
                ['refuse', 'adopt', ...policyChoices([ExistingResourcePolicy::Refuse, ExistingResourcePolicy::Adopt])],
            )
            ->expectsOutputToContain('already exists')
            ->assertFailed();
    });
});

describe('wizard', function (): void {
    it('refuses a re-run under --no-interaction, rolling back what it created', function (): void {
        FakeProvisioningEngine::addAccount('shop');

        $this->artisan('laranail::db-console.wizard', ['--server' => 'fake', '--db' => 'shop_prod', '--user' => 'shop', '--no-interaction' => true])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        expect(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(0);
    });

    it('re-runs without a password with --on-existing=adopt', function (): void {
        FakeProvisioningEngine::addDatabase('shop_prod');
        FakeProvisioningEngine::addAccount('shop');

        $this->artisan('laranail::db-console.wizard', ['--server' => 'fake', '--db' => 'shop_prod', '--user' => 'shop', '--on-existing' => 'adopt'])
            ->expectsOutputToContain('adopted as it was')
            ->assertSuccessful();

        expect(FakeProvisioningEngine::count('fake_grants', 'shop'))->toBe(1);
    });
});
