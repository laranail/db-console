<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Illuminate\Support\Facades\Notification;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Models\DbAccount;
use Simtabi\Laranail\DBConsole\Enums\ConsoleRole;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\User;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Events\AccountAdopted;
use Simtabi\Laranail\DBConsole\Events\AccountCreated;
use Simtabi\Laranail\DBConsole\Events\DatabaseAdopted;
use Simtabi\Laranail\DBConsole\Models\ManagedDatabase;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Exceptions\NotAuthorized;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Enums\NotificationCategory;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;
use Simtabi\Laranail\DBConsole\Services\ProvisioningWizard;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Events\AccountCreationRefused;
use Simtabi\Laranail\DBConsole\Events\AccountPasswordApplied;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Events\DatabaseCreationRefused;
use Simtabi\Laranail\DBConsole\Security\NullOperatorAssurance;
use Simtabi\Laranail\DBConsole\Exceptions\AccountAlreadyExists;
use Simtabi\Laranail\DBConsole\Exceptions\ConfirmationRequired;
use Simtabi\Laranail\DBConsole\Exceptions\DatabaseAlreadyExists;
use Simtabi\Laranail\DBConsole\Services\Results\OperationResult;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeProvisioningEngine;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FixedOperatorAssurance;
use Simtabi\Laranail\DBConsole\Notifications\ExistingResourceNotification;

/*
 * Owner decision 2026-10-17: an existing account or database is refused and
 * reported by default. Adopting it is an explicit override only an operator
 * holding resource.adopt may make, and applying a password to an adopted
 * account also needs account.rotate and a recent identity confirmation,
 * which the host reports through OperatorAssurance.
 */

const POLICY_THEIR_PASSWORD = 'theirs';

/**
 * The password db-console is asked to set, generated once per run so no
 * literal credential sits in the repository.
 */
function policyOurPassword(): string
{
    static $password = null;

    return $password ??= Password::generate()->reveal();
}

/**
 * Allow every ability except the ones listed, so a test can take one
 * permission away from an otherwise all-powerful operator.
 *
 * @param list<ConsolePermission> $denied
 */
function policyDenyOnly(array $denied): void
{
    $GLOBALS['policyDenied'] = array_map(static fn (ConsolePermission $p): string => $p->ability(), $denied);
}

beforeEach(function (): void {
    $this->migrateCatalog();
    $GLOBALS['policyDenied'] = [];
    Gate::before(fn ($user = null, string $ability = ''): bool => ! in_array($ability, $GLOBALS['policyDenied'], true));

    $this->engine = FakeProvisioningEngine::install();

    // An authenticated operator whose host says they confirmed just now, so
    // adopt_and_apply is decided by the permissions unless a test changes it.
    $operator = new User;
    $operator->id = 7;
    $this->actingAs($operator);
    app()->instance(OperatorAssurance::class, FixedOperatorAssurance::confirmedNow());
});

function policyTheirAccount(): void
{
    FakeProvisioningEngine::addAccount('shop', '%', POLICY_THEIR_PASSWORD);
}

function policyCreateShop(?ExistingResourcePolicy $policy = null, ?Password $password = null): OperationResult
{
    return app(AccountManager::class)->create('fake', new Username('shop'), new Host('%'), $password ?? new Password(policyOurPassword()), $policy);
}

function policyProvisionShop(?Password $password, ?ExistingResourcePolicy $policy = null): OperationResult
{
    return app(ProvisioningWizard::class)->provision(
        'fake',
        new DbName('shop_prod'),
        new Charset('utf8'),
        new Username('shop'),
        new Host('%'),
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
        $password,
        $policy,
    );
}

describe('an existing account', function (): void {
    it('is refused by default, and nothing about it changes', function (): void {
        Event::fake([AccountCreationRefused::class, AccountAdopted::class, AccountCreated::class]);
        policyTheirAccount();

        expect(fn (): OperationResult => policyCreateShop())->toThrow(AccountAlreadyExists::class);

        expect(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', POLICY_THEIR_PASSWORD))
            ->and(DbAccount::query()->count())->toBe(0);
        Event::assertDispatched(AccountCreationRefused::class);
        Event::assertNotDispatched(AccountAdopted::class);
        Event::assertNotDispatched(AccountCreated::class);
    });

    it('reports a refusal to the security recipients', function (): void {
        Notification::fake();
        config()->set('laranail.db-console.notifications.recipients.security', ['security@example.com']);
        policyTheirAccount();

        expect(fn (): OperationResult => policyCreateShop())->toThrow(AccountAlreadyExists::class);

        Notification::assertSentOnDemand(ExistingResourceNotification::class);
    });

    it('is adopted on request, keeping its own password, and audited as a security event', function (): void {
        Event::fake([AccountAdopted::class, AccountPasswordApplied::class]);
        policyTheirAccount();

        $result = policyCreateShop(ExistingResourcePolicy::Adopt);

        expect($result->alreadyExisted)->toBeTrue()
            ->and($result->data['adopted'])->toBeTrue()
            ->and($result->data['password_applied'])->toBeFalse()
            ->and($result->hasGeneratedPassword())->toBeFalse()
            ->and(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', POLICY_THEIR_PASSWORD))
            ->and(DbAccount::query()->count())->toBe(1);
        Event::assertDispatched(AccountAdopted::class, fn (AccountAdopted $e): bool => $e->notificationCategory() === NotificationCategory::Security);
        Event::assertNotDispatched(AccountPasswordApplied::class);
    });

    it('cannot be adopted without resource.adopt', function (): void {
        policyDenyOnly([ConsolePermission::ResourceAdopt]);
        policyTheirAccount();

        expect(fn (): OperationResult => policyCreateShop(ExistingResourcePolicy::Adopt))->toThrow(NotAuthorized::class);
        expect(DbAccount::query()->count())->toBe(0);
    });

    it('takes its password only with account.rotate as well', function (): void {
        policyDenyOnly([ConsolePermission::AccountRotate]);
        policyTheirAccount();

        expect(fn (): OperationResult => policyCreateShop(ExistingResourcePolicy::AdoptAndApply))->toThrow(NotAuthorized::class);
        expect(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', POLICY_THEIR_PASSWORD));
    });

    it('takes the supplied password under adopt_and_apply, asking the host about the operator', function (): void {
        Event::fake([AccountPasswordApplied::class]);
        $assurance = FixedOperatorAssurance::confirmedNow();
        app()->instance(OperatorAssurance::class, $assurance);
        policyTheirAccount();

        $result = app(AccountManager::class)->create('fake', new Username('shop'), new Host('%'), new Password(policyOurPassword()), ExistingResourcePolicy::AdoptAndApply);

        expect($result->data['password_applied'])->toBeTrue()
            ->and(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', policyOurPassword()))
            ->and($assurance->askedAbout)->toBe(['7']);
        Event::assertDispatched(AccountPasswordApplied::class);
    });

    it('changes nothing without a recent confirmation', function (?int $secondsAgo): void {
        config()->set('laranail.db-console.security.step_up.ttl', 300);
        app()->instance(OperatorAssurance::class, new FixedOperatorAssurance(
            $secondsAgo === null ? null : now()->subSeconds($secondsAgo)->toImmutable(),
        ));
        policyTheirAccount();

        expect(fn (): OperationResult => policyCreateShop(ExistingResourcePolicy::AdoptAndApply))->toThrow(ConfirmationRequired::class);
        expect(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', POLICY_THEIR_PASSWORD))
            ->and(DbAccount::query()->count())->toBe(0);
    })->with(['never confirmed' => [null], 'confirmed too long ago' => [301]]);

    it('fails closed with the default assurance, which knows of no confirmation', function (): void {
        app()->forgetInstance(OperatorAssurance::class);
        app()->offsetUnset(OperatorAssurance::class);
        app()->bind(OperatorAssurance::class, NullOperatorAssurance::class);
        policyTheirAccount();

        expect(fn (): OperationResult => policyCreateShop(ExistingResourcePolicy::AdoptAndApply))->toThrow(ConfirmationRequired::class);
    });

    it('fails closed with no authenticated operator', function (): void {
        auth()->logout();
        policyTheirAccount();

        expect(fn (): OperationResult => policyCreateShop(ExistingResourcePolicy::AdoptAndApply))->toThrow(ConfirmationRequired::class);
        expect(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', POLICY_THEIR_PASSWORD));
    });

    it('answers a missing confirmation with 403 confirmation.required', function (): void {
        $e = ConfirmationRequired::forAction('adopt_and_apply', 300);

        expect($e->code()->value)->toBe('confirmation.required')
            ->and($e->code()->httpStatus())->toBe(403)
            ->and($e->userMessage())->toContain('300 seconds');
    });

    it('gets a generated password under adopt_and_apply when none was supplied, shown once', function (): void {
        policyTheirAccount();

        $result = app(AccountManager::class)->create('fake', new Username('shop'), new Host('%'), null, ExistingResourcePolicy::AdoptAndApply);
        $generated = $result->takeGeneratedPassword();

        expect($generated)->toBeString()
            ->and(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', (string) $generated));
    });
});

describe('policy layers, most specific first', function (): void {
    it('reads the global default', function (): void {
        config()->set('laranail.db-console.accounts.on_existing', 'adopt');
        policyTheirAccount();

        expect(policyCreateShop()->data['adopted'])->toBeTrue();
    });

    it('lets a server override the global default', function (): void {
        config()->set('laranail.db-console.accounts.on_existing', 'adopt');
        config()->set('laranail.db-console.servers.fake.accounts.on_existing', 'refuse');
        policyTheirAccount();

        expect(fn (): OperationResult => policyCreateShop())->toThrow(AccountAlreadyExists::class);
    });

    it('lets a call override the server', function (): void {
        config()->set('laranail.db-console.servers.fake.accounts.on_existing', 'refuse');
        policyTheirAccount();

        expect(policyCreateShop(ExistingResourcePolicy::Adopt)->data['adopted'])->toBeTrue();
    });

    it('refuses when a configured policy is not a valid case, rather than adopting', function (): void {
        config()->set('laranail.db-console.accounts.on_existing', 'yes-please');
        policyTheirAccount();

        expect(fn (): OperationResult => policyCreateShop())->toThrow(AccountAlreadyExists::class);
    });

    it('creates a new account normally whatever the policy, with no adopt permission', function (): void {
        policyDenyOnly([ConsolePermission::ResourceAdopt, ConsolePermission::AccountRotate]);

        $result = policyCreateShop(ExistingResourcePolicy::AdoptAndApply);

        expect($result->alreadyExisted)->toBeFalse()
            ->and($result->data['adopted'])->toBeFalse()
            ->and(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', policyOurPassword()));
    });
});

describe('an existing database', function (): void {
    it('is refused by default', function (): void {
        Event::fake([DatabaseCreationRefused::class]);
        FakeProvisioningEngine::addDatabase('shop_prod');

        expect(fn () => app(DatabaseManager::class)->create('fake', new DbName('shop_prod'), new Charset('utf8')))
            ->toThrow(DatabaseAlreadyExists::class);
        expect(ManagedDatabase::query()->count())->toBe(0);
        Event::assertDispatched(DatabaseCreationRefused::class);
    });

    it('is adopted only by an operator holding resource.adopt', function (): void {
        Event::fake([DatabaseAdopted::class]);
        FakeProvisioningEngine::addDatabase('shop_prod');

        $result = app(DatabaseManager::class)->create('fake', new DbName('shop_prod'), new Charset('utf8'), ExistingResourcePolicy::Adopt);

        expect($result->data['adopted'])->toBeTrue()
            ->and(ManagedDatabase::query()->count())->toBe(1);
        Event::assertDispatched(DatabaseAdopted::class);

        policyDenyOnly([ConsolePermission::ResourceAdopt]);
        FakeProvisioningEngine::addDatabase('other_prod');
        expect(fn () => app(DatabaseManager::class)->create('fake', new DbName('other_prod'), new Charset('utf8'), ExistingResourcePolicy::Adopt))
            ->toThrow(NotAuthorized::class);
    });

    it('treats adopt_and_apply as adopt, since a database has no password', function (): void {
        policyDenyOnly([ConsolePermission::AccountRotate]);
        FakeProvisioningEngine::addDatabase('shop_prod');

        $result = app(DatabaseManager::class)->create('fake', new DbName('shop_prod'), new Charset('utf8'), ExistingResourcePolicy::AdoptAndApply);

        expect($result->data['adopted'])->toBeTrue();
    });

    it('reads databases.on_existing, not the account key', function (): void {
        config()->set('laranail.db-console.accounts.on_existing', 'adopt');
        FakeProvisioningEngine::addDatabase('shop_prod');

        expect(fn () => app(DatabaseManager::class)->create('fake', new DbName('shop_prod'), new Charset('utf8')))
            ->toThrow(DatabaseAlreadyExists::class);
    });
});

describe('the refusal names the options the caller may take', function (): void {
    it('lists adopt and adopt_and_apply for an operator holding both permissions', function (): void {
        policyTheirAccount();

        try {
            policyCreateShop();
            $this->fail('expected a refusal');
        } catch (AccountAlreadyExists $e) {
            $response = $e->render(Request::create('/', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));
        }

        expect($response->getStatusCode())->toBe(409)
            ->and($response->getData(true))->toMatchArray([
                'code'    => 'account.already_exists',
                'options' => ['adopt', 'adopt_and_apply'],
            ]);
    });

    it('lists only adopt without account.rotate, and nothing without resource.adopt', function (): void {
        policyTheirAccount();

        policyDenyOnly([ConsolePermission::AccountRotate]);
        try {
            policyCreateShop();
        } catch (AccountAlreadyExists $e) {
            expect($e->options())->toBe(['adopt']);
        }

        policyDenyOnly([ConsolePermission::ResourceAdopt]);
        try {
            policyCreateShop();
        } catch (AccountAlreadyExists $e) {
            expect($e->options())->toBe([]);
        }
    });

    it('never offers adopt_and_apply for a database', function (): void {
        FakeProvisioningEngine::addDatabase('shop_prod');

        try {
            app(DatabaseManager::class)->create('fake', new DbName('shop_prod'), new Charset('utf8'));
        } catch (DatabaseAlreadyExists $e) {
            expect($e->options())->toBe(['adopt'])
                ->and($e->code()->httpStatus())->toBe(409);
        }
    });
});

describe('who may adopt by default', function (): void {
    it('is the Owner role alone', function (): void {
        expect(ConsoleRole::Owner->permissions())->toContain(ConsolePermission::ResourceAdopt);

        foreach ([ConsoleRole::Admin, ConsoleRole::Operator, ConsoleRole::ReadOnly, ConsoleRole::Auditor] as $role) {
            expect($role->permissions())->not->toContain(ConsolePermission::ResourceAdopt);
        }
    });
});

describe('the provisioning wizard', function (): void {
    it('re-runs without a password when the operator may adopt', function (): void {
        FakeProvisioningEngine::addDatabase('shop_prod');
        policyTheirAccount();

        $result = policyProvisionShop(null, ExistingResourcePolicy::Adopt);

        expect($result->data['adopted'])->toBeTrue()
            ->and($result->hasGeneratedPassword())->toBeFalse()
            ->and(FakeProvisioningEngine::count('fake_grants', 'shop'))->toBe(1)
            ->and(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', POLICY_THEIR_PASSWORD));
    });

    it('never rotates on a re-run without a password, even under adopt_and_apply', function (): void {
        FakeProvisioningEngine::addDatabase('shop_prod');
        policyTheirAccount();

        $result = policyProvisionShop(null, ExistingResourcePolicy::AdoptAndApply);

        expect($result->hasGeneratedPassword())->toBeFalse()
            ->and(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', POLICY_THEIR_PASSWORD));
    });

    it('refuses a re-run by default, and undoes only what the run created', function (): void {
        policyTheirAccount();

        expect(fn (): OperationResult => policyProvisionShop(null))->toThrow(AccountAlreadyExists::class);

        expect(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(0)
            ->and(FakeProvisioningEngine::count('fake_accounts', 'shop'))->toBe(1);
    });

    it('refuses a re-run for an operator who may not adopt', function (): void {
        policyDenyOnly([ConsolePermission::ResourceAdopt]);
        policyTheirAccount();

        expect(fn (): OperationResult => policyProvisionShop(null, ExistingResourcePolicy::Adopt))->toThrow(NotAuthorized::class);

        expect(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(0)
            ->and(FakeProvisioningEngine::count('fake_accounts', 'shop'))->toBe(1);
    });

    it('never drops an adopted database or account on rollback', function (): void {
        FakeProvisioningEngine::addDatabase('shop_prod');
        policyTheirAccount();
        $this->engine->failGrants = true;

        expect(fn (): OperationResult => policyProvisionShop(new Password(policyOurPassword()), ExistingResourcePolicy::Adopt))->toThrow(DBConsoleException::class);

        expect(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(1)
            ->and(FakeProvisioningEngine::count('fake_accounts', 'shop'))->toBe(1);
    });

    it('applies an adopted account\'s new password last, so a failed grant leaves it as it was', function (): void {
        policyTheirAccount();
        $this->engine->failGrants = true;

        expect(fn (): OperationResult => policyProvisionShop(new Password(policyOurPassword()), ExistingResourcePolicy::AdoptAndApply))->toThrow(DBConsoleException::class);

        expect(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', POLICY_THEIR_PASSWORD))
            ->and(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(0);
    });

    it('checks the right to apply the password before it creates anything', function (): void {
        policyDenyOnly([ConsolePermission::AccountRotate]);
        policyTheirAccount();

        expect(fn (): OperationResult => policyProvisionShop(new Password(policyOurPassword()), ExistingResourcePolicy::AdoptAndApply))->toThrow(NotAuthorized::class);

        expect(FakeProvisioningEngine::count('fake_databases', 'shop_prod'))->toBe(0)
            ->and(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', POLICY_THEIR_PASSWORD));
    });

    it('applies the new password once the grant has landed', function (): void {
        policyTheirAccount();

        $result = policyProvisionShop(new Password(policyOurPassword()), ExistingResourcePolicy::AdoptAndApply);

        expect($result->data['password_applied'])->toBeTrue()
            ->and(FakeProvisioningEngine::passwordOf('shop'))->toBe(hash('sha256', policyOurPassword()))
            ->and(FakeProvisioningEngine::count('fake_grants', 'shop'))->toBe(1);
    });
});
