<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Servers\AdminConnection;
use Simtabi\Laranail\DBConsole\Exceptions\RollbackFailed;
use Simtabi\Laranail\DBConsole\Exceptions\OperationFailed;
use Simtabi\Laranail\DBConsole\Services\OwnershipReleasingDrop;
use Simtabi\Laranail\DBConsole\Exceptions\InsufficientPrivilege;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeOwnershipEngine;

/*
 * The orchestration around a Postgres role drop, against SQLite: the fake
 * engine's "membership" is a table that exists while the admin holds it. The
 * live group proves the SQL on a real server; this proves that nothing the
 * drop took is left behind when it fails.
 */

beforeEach(function (): void {
    config()->set('database.connections.drop_admin', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $this->admin = new AdminConnection('local', DB::connection('drop_admin'));
    $this->tables = fn (): array => array_map(
        static fn (object $row): string => $row->name,
        DB::connection('drop_admin')->select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"),
    );
});

afterEach(fn () => DB::purge('drop_admin'));

function dropWith(FakeOwnershipEngine $engine, AdminConnection $admin): void
{
    app(OwnershipReleasingDrop::class)->drop($engine, $admin, new Username('r'), new Host('%'), ['operation' => 'account.drop']);
}

it('takes membership, drops, and keeps going', function (): void {
    dropWith(new FakeOwnershipEngine, $this->admin);

    // On a real server DROP ROLE takes the membership with it; the fake
    // only shows that the steps ran in order.
    expect(($this->tables)())->toBe(['dropped', 'membership']);
});

it('gives membership back when the drop fails after taking it', function (): void {
    expect(fn () => dropWith(new FakeOwnershipEngine(dropFails: true), $this->admin))
        ->toThrow(OperationFailed::class);

    expect(($this->tables)())->toBe([]);
});

it('does not take membership the admin already has', function (): void {
    dropWith(new FakeOwnershipEngine(alreadyHolds: true), $this->admin);

    expect(($this->tables)())->toBe(['dropped']);
});

it('does not give back membership the admin already had', function (): void {
    DB::connection('drop_admin')->statement('CREATE TABLE membership (id INTEGER)');

    expect(fn () => dropWith(new FakeOwnershipEngine(alreadyHolds: true, dropFails: true), $this->admin))
        ->toThrow(OperationFailed::class);

    expect(($this->tables)())->toBe(['membership']);
});

it('refuses before changing anything when a dependent database cannot be reached', function (): void {
    expect(fn () => dropWith(new FakeOwnershipEngine(blockedDatabase: true), $this->admin))
        ->toThrow(InsufficientPrivilege::class, 'locked_db');

    expect(($this->tables)())->toBe([]);
});

it('escalates when giving membership back fails too', function (): void {
    try {
        dropWith(new FakeOwnershipEngine(dropFails: true, releaseMembershipFails: true), $this->admin);
        $this->fail('expected RollbackFailed');
    } catch (RollbackFailed $e) {
        expect($e->context())->toHaveKey('cause')
            ->and($e->getPrevious())->toBeInstanceOf(OperationFailed::class);
    }

    expect(($this->tables)())->toBe(['membership']);
});
