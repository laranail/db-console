<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\DBConsole\Enums\Assurance;
use Simtabi\Laranail\DBConsole\Security\BreakGlass;
use Simtabi\Laranail\DBConsole\Secrets\SecretRotator;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Exceptions\NotAuthorized;
use Simtabi\Laranail\DBConsole\Events\AssuranceShortfall;
use Simtabi\Laranail\DBConsole\Security\AssuranceEnforcer;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;
use Simtabi\Laranail\DBConsole\Exceptions\AssuranceRequired;
use Simtabi\Laranail\DBConsole\Security\NullOperatorAssurance;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\OperatorSecurity;

beforeEach(function (): void {
    $this->migrateCatalog();
    OperatorSecurity::setUp();
});

function enforce(string $mode): void
{
    config()->set('laranail.db-console.security.assurance.enforce', $mode);
}

function authorizeAs(object $operator, ConsolePermission $permission): void
{
    test()->actingAs($operator);
    app(Authorizer::class)->authorize($permission, 'server:prod');
}

/**
 * @return list<string>
 */
function missingFor(object $operator, ConsolePermission $permission): array
{
    try {
        authorizeAs($operator, $permission);
    } catch (AssuranceRequired $e) {
        return array_map(static fn (Assurance $a): string => $a->value, $e->missing());
    }

    return [];
}

describe('enforce', function (): void {
    it('requires assurance in production and only reports it elsewhere, when unset', function (): void {
        $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());
        config()->set('laranail.db-console.security.assurance.enforce');

        authorizeAs($operator, ConsolePermission::DatabaseCreate);   // testing: report, so allowed

        app()->detectEnvironment(fn (): string => 'production');

        try {
            expect(fn () => authorizeAs($operator, ConsolePermission::DatabaseCreate))->toThrow(AssuranceRequired::class);
        } finally {
            // The catalog rollback at teardown asks for confirmation in production.
            app()->detectEnvironment(fn (): string => 'testing');
        }
    });

    it('checks nothing when off', function (): void {
        enforce('off');
        Event::fake([AssuranceShortfall::class]);

        authorizeAs(OperatorSecurity::owner(OperatorSecurity::unenrolled()), ConsolePermission::DatabaseDrop);

        Event::assertNotDispatched(AssuranceShortfall::class);
    });

    it('in report mode refuses nothing, audits every shortfall and alerts once a day', function (): void {
        enforce('report');
        Event::fake([AssuranceShortfall::class]);
        $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());

        authorizeAs($operator, ConsolePermission::DatabaseCreate);
        authorizeAs($operator, ConsolePermission::AccountCreate);

        Event::assertDispatchedTimes(AssuranceShortfall::class, 2);
        Event::assertDispatched(AssuranceShortfall::class, fn (AssuranceShortfall $e): bool => $e->verdict === AssuranceShortfall::WOULD_REFUSE && $e->alert);
        Event::assertDispatched(AssuranceShortfall::class, fn (AssuranceShortfall $e): bool => ! $e->alert);
    });

    it('treats an unrecognised enforce value as require', function (): void {
        enforce('of');   // a typo for off

        expect(missingFor(OperatorSecurity::owner(OperatorSecurity::unenrolled()), ConsolePermission::DatabaseCreate))
            ->toBe(['second_factor']);
    });
});

describe('require', function (): void {
    beforeEach(fn () => enforce('require'));

    it('needs a second factor for writes and not for reads, by default', function (): void {
        $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());

        expect(missingFor($operator, ConsolePermission::DatabaseCreate))->toBe(['second_factor'])
            ->and(missingFor($operator, ConsolePermission::DatabaseView))->toBe([]);
    });

    it('can require it for every permission, or for none', function (): void {
        $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());

        config()->set('laranail.db-console.security.assurance.second_factor', 'all');
        expect(missingFor($operator, ConsolePermission::DatabaseView))->toBe(['second_factor']);

        config()->set('laranail.db-console.security.assurance.second_factor', 'none');
        expect(missingFor($operator, ConsolePermission::DatabaseCreate))->toBe([]);

        config()->set('laranail.db-console.security.assurance.second_factor', 'nnone');   // a typo
        expect(missingFor($operator, ConsolePermission::DatabaseView))->toBe(['second_factor']);
    });

    it('needs a recent confirmation for the dangerous operations', function (ConsolePermission $permission): void {
        $operator = OperatorSecurity::owner(OperatorSecurity::enrolled());

        expect(missingFor($operator, $permission))->toBe(['recent_confirmation']);

        OperatorSecurity::confirm($operator, secondsAgo: 60);
        expect(missingFor($operator, $permission))->toBe([]);
    })->with([
        'drop database'   => [ConsolePermission::DatabaseDrop],
        'drop account'    => [ConsolePermission::AccountDrop],
        'rotate password' => [ConsolePermission::AccountRotate],
        'change host'     => [ConsolePermission::AccountEdit],
        'manage secrets'  => [ConsolePermission::SecretsManage],
    ]);

    it('does not count a confirmation older than the step-up ttl', function (): void {
        config()->set('laranail.db-console.security.step_up.ttl', 300);
        $operator = OperatorSecurity::confirm(OperatorSecurity::owner(OperatorSecurity::enrolled()), secondsAgo: 301);

        expect(missingFor($operator, ConsolePermission::DatabaseDrop))->toBe(['recent_confirmation']);
    });

    it('lists everything that is missing', function (): void {
        expect(missingFor(OperatorSecurity::owner(OperatorSecurity::unenrolled()), ConsolePermission::DatabaseDrop))
            ->toBe(['second_factor', 'recent_confirmation']);
    });

    it('refuses everyone while the host binds no OperatorAssurance', function (): void {
        app()->instance(OperatorAssurance::class, new NullOperatorAssurance);
        app()->forgetInstance(AssuranceEnforcer::class);
        app()->forgetInstance(Authorizer::class);

        expect(missingFor(OperatorSecurity::owner(OperatorSecurity::enrolled()), ConsolePermission::DatabaseCreate))
            ->toBe(['second_factor']);
    });

    it('lets a break-glass bypass through until it expires', function (): void {
        $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());
        app(BreakGlass::class)->issue($operator, 'root');

        expect(missingFor($operator, ConsolePermission::DatabaseDrop))->toBe([]);

        $this->travel(16)->minutes();
        expect(missingFor($operator, ConsolePermission::DatabaseDrop))->toBe(['second_factor', 'recent_confirmation']);
    });

    it('reports a gate denial as NotAuthorized before assurance is considered', function (): void {
        Event::fake([AssuranceShortfall::class]);

        expect(fn () => authorizeAs(OperatorSecurity::unenrolled('stranger'), ConsolePermission::DatabaseDrop))
            ->toThrow(NotAuthorized::class);
        Event::assertNotDispatched(AssuranceShortfall::class);
    });

    it('leaves a run with no operator to the gate', function (): void {
        Gate::before(fn ($user = null): bool => true);

        app(Authorizer::class)->authorize(ConsolePermission::DatabaseDrop, 'server:prod');

        expect(true)->toBeTrue();
    });

    it('applies to secrets rotation, which has no RBAC check, only when an operator is signed in', function (): void {
        expect(app(SecretRotator::class)->rotateAll())->toBe(0);   // the shell

        $this->actingAs(OperatorSecurity::owner(OperatorSecurity::enrolled()));
        expect(fn () => app(SecretRotator::class)->rotateAll())->toThrow(AssuranceRequired::class);
    });
});

it('tells the operator what to do, and gives an API client a machine-readable body', function (): void {
    enforce('require');

    try {
        authorizeAs(OperatorSecurity::owner(OperatorSecurity::unenrolled()), ConsolePermission::DatabaseDrop);
        $this->fail('expected AssuranceRequired');
    } catch (AssuranceRequired $e) {
        expect($e->userMessage())->toContain('Enrol in two-factor')->toContain('Confirm your password');

        $request = Request::create('/api/db-console/servers/prod/databases/shop', 'DELETE');
        $request->headers->set('Accept', 'application/json');
        $response = $e->render($request);

        expect($response)->toBeInstanceOf(JsonResponse::class)
            ->and($response?->getStatusCode())->toBe(403)
            ->and($response?->getData(true))->toMatchArray([
                'code'    => 'assurance_required',
                'missing' => ['second_factor', 'recent_confirmation'],
            ]);
    }
});
