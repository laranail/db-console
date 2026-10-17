<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Models\SecurityBypass;
use Simtabi\Laranail\DBConsole\Events\BreakGlassIssued;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\OperatorSecurity;

beforeEach(function (): void {
    $this->migrateCatalog();
    OperatorSecurity::setUp();
});

it('issues a 15-minute bypass from the shell and raises a critical alert', function (): void {
    Event::fake([BreakGlassIssued::class]);
    $operator = OperatorSecurity::unenrolled();

    expect(Artisan::call('laranail::db-console.security.recover', ['user' => $operator->id]))->toBe(0);

    $bypass = SecurityBypass::query()->sole();
    expect((int) round(now()->diffInMinutes($bypass->expires_at)))->toBe(15);
    Event::assertDispatched(BreakGlassIssued::class, fn (BreakGlassIssued $e): bool => $e->severity() === Severity::Critical);
});

it('ends the earlier bypass when a new one is issued', function (): void {
    $operator = OperatorSecurity::unenrolled();
    Artisan::call('laranail::db-console.security.recover', ['user' => $operator->id]);
    Artisan::call('laranail::db-console.security.recover', ['user' => $operator->id]);

    expect(SecurityBypass::query()->where('expires_at', '>', now())->count())->toBe(1);
});

it('refuses to run from an HTTP request', function (): void {
    $operator = OperatorSecurity::unenrolled();
    OperatorSecurity::overHttp();

    expect(Artisan::call('laranail::db-console.security.recover', ['user' => $operator->id]))->toBe(1)
        ->and(SecurityBypass::query()->count())->toBe(0);
});

it('fails on an unknown operator', function (): void {
    expect(Artisan::call('laranail::db-console.security.recover', ['user' => '999']))->toBe(1);
});
