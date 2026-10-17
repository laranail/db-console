<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\OperatorSecurity;
use Simtabi\Laranail\DBConsole\Security\SessionConfirmationAssurance;

beforeEach(function (): void {
    $this->migrateCatalog();
    OperatorSecurity::setUp(bindHostAssurance: false);
});

function requestWithSession(?int $confirmedAt): void
{
    $request = Request::create('/db-console', 'POST');
    $session = app('session')->driver('array');
    if ($confirmedAt !== null) {
        $session->put('auth.password_confirmed_at', $confirmedAt);
    }
    $request->setLaravelSession($session);
    app()->instance('request', $request);
}

it("reads Laravel's password-confirmation timestamp", function (): void {
    $at = time() - 42;
    requestWithSession($at);

    expect(app(SessionConfirmationAssurance::class)->recentlyConfirmedAt(OperatorSecurity::unenrolled())?->getTimestamp())->toBe($at);
});

it('reports no confirmation without one, or without a session, and never a second factor', function (): void {
    $assurance = app(SessionConfirmationAssurance::class);
    $operator = OperatorSecurity::unenrolled();

    requestWithSession(null);
    expect($assurance->recentlyConfirmedAt($operator))->toBeNull();

    app()->instance('request', Request::create('/api', 'GET'));
    expect($assurance->recentlyConfirmedAt($operator))->toBeNull()
        ->and($assurance->hasSecondFactor($operator))->toBeFalse();
});
