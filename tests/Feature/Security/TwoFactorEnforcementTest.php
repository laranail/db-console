<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Simtabi\Laranail\DBConsole\Security\BreakGlass;
use Simtabi\Laranail\DBConsole\Models\TwoFactorGrace;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Events\TwoFactorEnforced;
use Simtabi\Laranail\DBConsole\Exceptions\NotAuthorized;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;
use Simtabi\Laranail\DBConsole\Exceptions\TwoFactorRequired;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\OperatorSecurity;
use Simtabi\Laranail\DBConsole\Notifications\TwoFactorEnrolmentNotification;

beforeEach(function (): void {
    $this->migrateCatalog();
    OperatorSecurity::setUp();
});

function enforce(string $mode): void
{
    config()->set('laranail.db-console.security.two_factor.enforce', $mode);
}

function authorizeAs(object $operator, ConsolePermission $permission): void
{
    test()->actingAs($operator);
    app(Authorizer::class)->authorize($permission, 'server:prod');
}

it('requires two-factor in production and only reports it elsewhere, when enforce is unset', function (): void {
    $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());
    config()->set('laranail.db-console.security.two_factor.enforce');

    authorizeAs($operator, ConsolePermission::DatabaseDrop);   // testing: report, so allowed

    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect(fn () => authorizeAs($operator, ConsolePermission::DatabaseDrop))->toThrow(TwoFactorRequired::class);
    } finally {
        // The catalog rollback at teardown asks for confirmation in production.
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('lets an operator without two-factor through when enforcement is off', function (): void {
    enforce('off');
    Event::fake([TwoFactorEnforced::class]);

    authorizeAs(OperatorSecurity::owner(OperatorSecurity::unenrolled()), ConsolePermission::DatabaseDrop);

    Event::assertNotDispatched(TwoFactorEnforced::class);
    expect(TwoFactorGrace::query()->count())->toBe(0);
});

it('in report mode refuses nothing, alerts once a day, and never starts the grace clock', function (): void {
    enforce('report');
    Event::fake([TwoFactorEnforced::class]);
    $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());

    authorizeAs($operator, ConsolePermission::DatabaseDrop);
    authorizeAs($operator, ConsolePermission::AccountRotate);

    Event::assertDispatchedTimes(TwoFactorEnforced::class, 1);
    Event::assertDispatched(TwoFactorEnforced::class, fn (TwoFactorEnforced $e): bool => $e->verdict === TwoFactorEnforced::WOULD_REFUSE);
    expect(TwoFactorGrace::query()->sole()->first_refused_at)->toBeNull();

    $this->travel(25)->hours();
    authorizeAs($operator, ConsolePermission::DatabaseDrop);
    Event::assertDispatchedTimes(TwoFactorEnforced::class, 2);
});

it('refuses writes from the first request but keeps reads during the grace window', function (): void {
    enforce('require');
    Event::fake([TwoFactorEnforced::class]);
    $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());

    expect(fn () => authorizeAs($operator, ConsolePermission::DatabaseDrop))->toThrow(TwoFactorRequired::class);

    authorizeAs($operator, ConsolePermission::DatabaseView);   // a read, inside the window

    expect(TwoFactorGrace::query()->sole()->first_refused_at)->not->toBeNull();
    Event::assertDispatchedTimes(TwoFactorEnforced::class, 1);
});

it('refuses reads too once the grace window has ended', function (): void {
    enforce('require');
    config()->set('laranail.db-console.security.two_factor.grace_days', 7);
    $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());

    authorizeAs($operator, ConsolePermission::DatabaseView);   // opens the window
    $this->travel(7 * 24 * 60 + 1)->minutes();

    expect(fn () => authorizeAs($operator, ConsolePermission::DatabaseView))->toThrow(TwoFactorRequired::class);
});

it('counts the grace window from the first refused request, not from enabling enforcement', function (): void {
    enforce('require');
    $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());

    $this->travel(30)->days();

    expect(fn () => authorizeAs($operator, ConsolePermission::DatabaseView))->not->toThrow(TwoFactorRequired::class);
});

it('allows an enrolled operator, and refuses one without recovery codes', function (): void {
    enforce('require');

    authorizeAs(OperatorSecurity::owner(OperatorSecurity::enrolled()), ConsolePermission::DatabaseDrop);

    expect(fn () => authorizeAs(OperatorSecurity::owner(OperatorSecurity::confirmedWithoutCodes()), ConsolePermission::DatabaseDrop))
        ->toThrow(TwoFactorRequired::class);
});

it('lets a break-glass bypass through until it expires', function (): void {
    enforce('require');
    $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());
    app(BreakGlass::class)->issue($operator, 'root');

    authorizeAs($operator, ConsolePermission::DatabaseDrop);

    $this->travel(16)->minutes();

    expect(fn () => authorizeAs($operator, ConsolePermission::DatabaseDrop))->toThrow(TwoFactorRequired::class);
});

it('tells the operator, not only the security recipients', function (): void {
    enforce('require');
    Notification::fake();
    $operator = OperatorSecurity::owner(OperatorSecurity::unenrolled());

    try {
        authorizeAs($operator, ConsolePermission::DatabaseDrop);
    } catch (TwoFactorRequired) {
    }

    Notification::assertSentTo($operator, TwoFactorEnrolmentNotification::class);
});

it('reports a gate denial as NotAuthorized before two-factor is considered', function (): void {
    enforce('require');
    Event::fake([TwoFactorEnforced::class]);

    expect(fn () => authorizeAs(OperatorSecurity::unenrolled('stranger'), ConsolePermission::DatabaseDrop))
        ->toThrow(NotAuthorized::class);

    Event::assertNotDispatched(TwoFactorEnforced::class);
});

it('treats an unrecognised enforce value as require', function (): void {
    enforce('of');   // a typo for off

    expect(fn () => authorizeAs(OperatorSecurity::owner(OperatorSecurity::unenrolled()), ConsolePermission::DatabaseDrop))
        ->toThrow(TwoFactorRequired::class);
});
