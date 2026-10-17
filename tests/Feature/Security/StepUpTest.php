<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Events\StepUpFailed;
use Simtabi\Laranail\DBConsole\Security\BreakGlass;
use Simtabi\Laranail\DBConsole\Security\StepUpGate;
use Simtabi\Laranail\DBConsole\Security\StepUpProof;
use Simtabi\Laranail\DBConsole\Enums\SensitiveAction;
use Simtabi\Laranail\DBConsole\Secrets\SecretRotator;
use Simtabi\Laranail\DBConsole\Events\DatabaseDropped;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Exceptions\StepUpRequired;
use Simtabi\Laranail\DBConsole\Exceptions\UnsupportedOperation;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\OperatorSecurity;
use Simtabi\Laranail\DBConsole\Security\Contracts\StepUpVerifier;

beforeEach(function (): void {
    $this->migrateCatalog();
    OperatorSecurity::setUp();
    config()->set('laranail.db-console.security.two_factor.enforce', 'off');
    $this->operator = OperatorSecurity::enrolled();
    $this->actingAs($this->operator);
});

function stepUp(SensitiveAction $action = SensitiveAction::DatabaseDrop): void
{
    app(StepUpGate::class)->require($action, 'prod');
}

function withSession(?int $confirmedAt): void
{
    $request = Request::create('/db-console', 'POST');
    $session = app('session')->driver('array');
    if ($confirmedAt !== null) {
        $session->put('auth.password_confirmed_at', $confirmedAt);
    }
    $request->setLaravelSession($session);
    app()->instance('request', $request);
}

function useStepUp(string $driver): void
{
    config()->set('laranail.db-console.security.step_up.driver', $driver);
    app()->forgetInstance(StepUpVerifier::class);
    app()->forgetInstance(StepUpGate::class);
}

describe('session driver', function (): void {
    beforeEach(fn () => useStepUp('session'));

    it('passes on a password.confirm within the ttl', function (): void {
        withSession(time() - 60);

        stepUp();

        expect(true)->toBeTrue();
    });

    it('refuses a confirmation older than the ttl, and alerts', function (): void {
        Event::fake([StepUpFailed::class]);
        config()->set('laranail.db-console.security.step_up.ttl', 300);
        withSession(time() - 301);

        expect(fn () => stepUp())->toThrow(StepUpRequired::class);
        Event::assertDispatched(StepUpFailed::class, fn (StepUpFailed $e): bool => $e->action === 'database.drop' && ! $e->proofPresented);
    });

    it('refuses a session with no confirmation at all', function (): void {
        withSession(null);

        expect(fn () => stepUp())->toThrow(StepUpRequired::class);
    });

    it('asks a sessionless caller for a one-time code instead', function (): void {
        $authkit = OperatorSecurity::fakeAuthKit();
        app(StepUpProof::class)->present('123456');

        stepUp();

        expect($authkit->verifications)->toBe(1);
    });
});

describe('totp driver', function (): void {
    beforeEach(fn () => useStepUp('totp'));

    it('passes a code authkit verifies, and checks it only once per request', function (): void {
        $authkit = OperatorSecurity::fakeAuthKit();
        app(StepUpProof::class)->present('123456');

        stepUp(SensitiveAction::AccountDrop);
        stepUp(SensitiveAction::AccountRotate);

        expect($authkit->verifications)->toBe(1);
    });

    it('refuses a wrong code and records that one was presented', function (): void {
        Event::fake([StepUpFailed::class]);
        OperatorSecurity::fakeAuthKit();
        app(StepUpProof::class)->present('000000');

        expect(fn () => stepUp())->toThrow(StepUpRequired::class);
        Event::assertDispatched(StepUpFailed::class, fn (StepUpFailed $e): bool => $e->proofPresented && $e->driver === 'totp');
    });

    it('refuses everything when authkit is absent, rather than passing', function (): void {
        app(StepUpProof::class)->present('123456');

        expect(fn () => stepUp())->toThrow(StepUpRequired::class);
    });

    it('does not carry a verified proof into the next request', function (): void {
        OperatorSecurity::fakeAuthKit();
        app(StepUpProof::class)->present('123456');
        stepUp();

        app()->forgetScopedInstances();

        expect(fn () => stepUp())->toThrow(StepUpRequired::class);
    });
});

it('passes everything with the none driver', function (): void {
    useStepUp('none');

    stepUp();

    expect(true)->toBeTrue();
});

it('treats an unrecognised driver as session, never as none', function (): void {
    useStepUp('nnone');
    withSession(null);

    expect(fn () => stepUp())->toThrow(StepUpRequired::class);
});

it('lets a break-glass bypass stand in for step-up exactly once', function (): void {
    useStepUp('totp');
    app(BreakGlass::class)->issue($this->operator, 'root');

    stepUp();
    app()->forgetScopedInstances();

    expect(fn () => stepUp())->toThrow(StepUpRequired::class);
});

it('trusts a shell run with no operator, and refuses an HTTP request with none', function (): void {
    useStepUp('totp');
    auth()->logout();

    stepUp();

    OperatorSecurity::overHttp();
    expect(fn () => stepUp())->toThrow(StepUpRequired::class);
});

describe('every sensitive service call asks for it', function (): void {
    beforeEach(function (): void {
        useStepUp('totp');
        Gate::before(fn ($user = null): bool => true);
        config()->set('laranail.db-console.backup.enabled', false);
        config()->set('database.connections.stepup_admin', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('laranail.db-console.servers.lite', [
            'engine' => 'sqlite', 'connection' => 'stepup_admin', 'tls' => ['enabled' => false],
        ]);
    });

    it('drop database', function (): void {
        Event::fake([DatabaseDropped::class]);

        expect(fn () => app(DatabaseManager::class)->drop('lite', new DbName('shop_prod')))->toThrow(StepUpRequired::class);
        Event::assertNotDispatched(DatabaseDropped::class);

        OperatorSecurity::fakeAuthKit();
        app(StepUpProof::class)->present('123456');
        app(DatabaseManager::class)->drop('lite', new DbName('shop_prod'));
        Event::assertDispatched(DatabaseDropped::class);
    });

    it('drop account, rotate and change host', function (string $operation): void {
        $call = fn () => match ($operation) {
            'drop'        => app(AccountManager::class)->drop('lite', new Username('app'), new Host('%')),
            'rotate'      => app(AccountManager::class)->rotatePassword('lite', new Username('app'), new Host('%')),
            'change host' => app(AccountManager::class)->changeHost('lite', new Username('app'), new Host('%'), new Host('10.0.0.%')),
        };

        expect($call)->toThrow(StepUpRequired::class);

        // With the proof the call gets past step-up, to SQLite's own refusal.
        OperatorSecurity::fakeAuthKit();
        app(StepUpProof::class)->present('123456');
        expect($call)->toThrow(UnsupportedOperation::class);
    })->with(['drop', 'rotate', 'change host']);

    it('secrets rotation', function (): void {
        expect(fn () => app(SecretRotator::class)->rotateAll())->toThrow(StepUpRequired::class);

        OperatorSecurity::fakeAuthKit();
        app(StepUpProof::class)->present('123456');
        expect(app(SecretRotator::class)->rotateAll())->toBe(0);
    });
});
