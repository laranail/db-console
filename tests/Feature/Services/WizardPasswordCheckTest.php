<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Events\DatabaseCreated;
use Simtabi\Laranail\DBConsole\Exceptions\WeakPassword;
use Simtabi\Laranail\DBConsole\Services\ProvisioningWizard;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Engines\PasswordConstrainedEngine;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeProvisioningEngine;

/*
 * Postgres refuses a password it would rewrite before hashing (SASLprep)
 * when it builds the role's statement. In the wizard that was the second
 * step: the database already existed, and the rollback dropped it again.
 * The wizard now asks the engine first, and nothing is created.
 */

beforeEach(function (): void {
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);
    $this->engine = FakeProvisioningEngine::install();
});

function provisionWith(Password $password): void
{
    app(ProvisioningWizard::class)->provision(
        'fake',
        new DbName('shop_prod'),
        new Charset('utf8'),
        new Username('shop'),
        new Host('%'),
        PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly),
        $password,
    );
}

it('refuses a password the engine cannot take before creating the database', function (): void {
    Event::fake([DatabaseCreated::class]);
    $this->engine->refusesPasswords = true;

    expect($this->engine)->toBeInstanceOf(PasswordConstrainedEngine::class)
        ->and(fn () => provisionWith(Password::generate()))->toThrow(WeakPassword::class);

    Event::assertNotDispatched(DatabaseCreated::class);
    expect(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(0);
});

it('provisions as before when the engine takes the password', function (): void {
    provisionWith(Password::generate());

    expect(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(1)
        ->and(FakeProvisioningEngine::count('fake_accounts', 'shop'))->toBe(1);
});
