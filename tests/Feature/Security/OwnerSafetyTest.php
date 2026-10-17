<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\DBConsole\Models\RoleAssignment;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Simtabi\Laranail\DBConsole\Exceptions\OwnerWouldBeStranded;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\OperatorSecurity;

beforeEach(function (): void {
    $this->migrateCatalog();
    OperatorSecurity::setUp();
    config()->set('laranail.db-console.security.two_factor.enforce', 'require');
});

function revokeOwner(object $operator): int
{
    return Artisan::call('laranail::db-console.role:revoke', ['--user' => $operator->id, '--role' => 'owner', '--scope' => 'global']);
}

function ownerRows(): int
{
    return RoleAssignment::query()->where('role', 'owner')->count();
}

it('refuses to revoke the last global Owner', function (): void {
    $only = OperatorSecurity::owner(OperatorSecurity::enrolled());

    expect(revokeOwner($only))->toBe(1)
        ->and(ownerRows())->toBe(1);
});

it('refuses to revoke the last enrolled Owner while the others have no two-factor', function (): void {
    $enrolled = OperatorSecurity::owner(OperatorSecurity::enrolled());
    OperatorSecurity::owner(OperatorSecurity::unenrolled());

    expect(revokeOwner($enrolled))->toBe(1)
        ->and(ownerRows())->toBe(2);
});

it('allows it once another Owner is enrolled, or when enforcement is off', function (): void {
    $first = OperatorSecurity::owner(OperatorSecurity::enrolled('first'));
    $unenrolled = OperatorSecurity::owner(OperatorSecurity::unenrolled());

    config()->set('laranail.db-console.security.two_factor.enforce', 'off');
    expect(revokeOwner($first))->toBe(0);

    config()->set('laranail.db-console.security.two_factor.enforce', 'require');
    OperatorSecurity::owner($first);
    OperatorSecurity::owner(OperatorSecurity::enrolled('second'));
    expect(revokeOwner($first))->toBe(0)
        ->and(revokeOwner($unenrolled))->toBe(0);
});

it('leaves other roles and scopes alone', function (): void {
    $only = OperatorSecurity::owner(OperatorSecurity::enrolled());
    app(RbacDriver::class)->assign($only, 'owner', 'server:prod');

    expect(Artisan::call('laranail::db-console.role:revoke', ['--user' => $only->id, '--role' => 'owner', '--scope' => 'server:prod']))->toBe(0);
});

it('refuses the last enrolled Owner disabling two-factor over HTTP', function (): void {
    $owner = OperatorSecurity::owner(OperatorSecurity::enrolled());
    OperatorSecurity::owner(OperatorSecurity::unenrolled());
    OperatorSecurity::overHttp();

    expect(fn () => $owner->forceFill(['two_factor_confirmed_at' => null])->save())->toThrow(OwnerWouldBeStranded::class)
        ->and($owner->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('allows it with another enrolled Owner, and from the shell', function (): void {
    $owner = OperatorSecurity::owner(OperatorSecurity::enrolled('first'));

    $owner->forceFill(['two_factor_confirmed_at' => null])->save();   // the shell: an administrator resetting a lost device
    expect($owner->fresh()->two_factor_confirmed_at)->toBeNull();

    $owner->forceFill(['two_factor_confirmed_at' => now()])->save();
    OperatorSecurity::owner(OperatorSecurity::enrolled('second'));
    OperatorSecurity::overHttp();

    $owner->forceFill(['two_factor_confirmed_at' => null])->save();
    expect($owner->fresh()->two_factor_confirmed_at)->toBeNull();
});
