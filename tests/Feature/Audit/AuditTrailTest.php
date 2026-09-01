<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Audit\AuditChain;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Enums\OperationOutcome;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Events\DatabaseCreated;
use Simtabi\Laranail\DBConsole\Events\DatabaseDropped;
use Simtabi\Laranail\DBConsole\Events\OperationFailed;
use Simtabi\Laranail\DBConsole\Exceptions\NotAuthorized;
use Simtabi\Laranail\DBConsole\Models\AuditLog;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;

beforeEach(function (): void {
    $this->migrateCatalog();
});

describe('append-only, hash-chained audit trail', function (): void {
    it('appends one row per dispatched event, with actor/action/target/outcome', function (): void {
        event(new DatabaseCreated('prod-mysql', ['target' => 'shop_prod']));

        expect(AuditLog::query()->count())->toBe(1);

        $row = AuditLog::query()->first();
        expect($row->action)->toBe(OperationType::DatabaseCreate)
            ->and($row->target)->toBe('shop_prod')
            ->and($row->server)->toBe('prod-mysql')
            ->and($row->outcome)->toBe(OperationOutcome::Succeeded)
            ->and($row->hash)->toBeString()
            ->and($row->previous_hash)->toBeNull();   // genesis
    });

    it('links each row to the previous one, forming a verifiable chain', function (): void {
        event(new DatabaseCreated('prod-mysql', ['target' => 'a']));
        event(new DatabaseCreated('prod-mysql', ['target' => 'b']));
        event(new DatabaseDropped('prod-mysql', ['target' => 'a']));

        $rows = AuditLog::query()->orderBy('created_at')->orderBy('id')->get();

        expect($rows)->toHaveCount(3)
            ->and($rows[1]->previous_hash)->toBe($rows[0]->hash)
            ->and($rows[2]->previous_hash)->toBe($rows[1]->hash);

        $result = app(AuditChain::class)->verify();
        expect($result['valid'])->toBeTrue()
            ->and($result['checked'])->toBe(3)
            ->and($result['broken_at'])->toBeNull();
    });

    it('detects tampering — an altered historical row breaks the chain', function (): void {
        event(new DatabaseCreated('prod-mysql', ['target' => 'a']));
        event(new DatabaseCreated('prod-mysql', ['target' => 'b']));

        // Tamper directly at the storage layer, bypassing the model observer.
        $first = AuditLog::query()->orderBy('created_at')->first();
        DB::connection('db_console_catalog')->table('db_console_audit_log')
            ->where('id', $first->id)
            ->update(['target' => 'tampered']);

        $result = app(AuditChain::class)->verify();
        expect($result['valid'])->toBeFalse()
            ->and($result['broken_at'])->toBe($first->id);
    });
});

describe('append-only enforcement (observer)', function (): void {
    it('blocks updates to audit rows', function (): void {
        event(new DatabaseCreated('prod-mysql', ['target' => 'a']));
        $row = AuditLog::query()->first();

        expect(function () use ($row): void {
            $row->target = 'changed';
            $row->save();
        })->toThrow(RuntimeException::class);
    });

    it('blocks deletes of audit rows', function (): void {
        event(new DatabaseCreated('prod-mysql', ['target' => 'a']));
        $row = AuditLog::query()->first();

        expect(fn () => $row->delete())->toThrow(RuntimeException::class);
    });
});

describe('failures and rollbacks are audited too', function (): void {
    it('records a failed drop with the failed outcome', function (): void {
        event(new OperationFailed(
            'prod-mysql',
            OperationType::DatabaseDrop,
            ['target' => 'shop_prod', 'code' => 'operation.failed'],
        ));

        $row = AuditLog::query()->first();
        expect($row->outcome)->toBe(OperationOutcome::Failed)
            ->and($row->action)->toBe(OperationType::DatabaseDrop);
    });
});

describe('deny decisions are audited (section 20)', function (): void {
    it('records an audit row when a gate denies a sensitive action', function (): void {
        // Deny-by-default is in force (no roles assigned); no Gate::before here.
        $authorizer = app(Authorizer::class);

        expect(fn () => $authorizer->authorize(ConsolePermission::DatabaseDrop, 'server:prod-mysql'))
            ->toThrow(NotAuthorized::class);

        $row = AuditLog::query()->first();
        expect($row)->not->toBeNull()
            ->and($row->target)->toBe('db-console.database.drop')
            ->and($row->server)->toBe('prod-mysql');
    });

    it('deny-by-default: with no roles assigned, every gate check is denied', function (): void {
        expect(Gate::allows(ConsolePermission::DatabaseView->ability(), 'server:prod-mysql'))->toBeFalse()
            ->and(Gate::allows(ConsolePermission::Access->ability()))->toBeFalse();
    });
});
