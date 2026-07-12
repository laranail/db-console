<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Access\Scope;
use Simtabi\Laranail\DBConsole\Enums\ScopeType;

describe('parsing', function (): void {
    it('parses each scope form', function (): void {
        expect(Scope::parse('global')->type)->toBe(ScopeType::Global)
            ->and(Scope::parse(null)->type)->toBe(ScopeType::Global)
            ->and(Scope::parse('server:prod-mysql')->server)->toBe('prod-mysql')
            ->and(Scope::parse('database:prod-mysql/shop_prod')->databasePattern)->toBe('shop_prod')
            ->and(Scope::parse('database:prod-mysql/shop_*')->databasePattern)->toBe('shop_*');
    });

    it('round-trips to a wire string', function (string $wire): void {
        expect(Scope::parse($wire)->toString())->toBe($wire);
    })->with(['global', 'server:prod-mysql', 'database:prod-mysql/shop_prod', 'database:prod-mysql/shop_*']);

    it('rejects malformed scopes', function (string $bad): void {
        Scope::parse($bad);
    })->with(['nonsense', 'database:prod-mysql'])->throws(InvalidArgumentException::class);
});

/*
 * The scope-resolution MATRIX (section 27). Widest covers narrowest, never
 * the reverse. This is a primary access control — it must never be weakened.
 */
describe('coverage matrix (global ⊇ server ⊇ database)', function (): void {
    it('global covers every target', function (string $target): void {
        expect(Scope::parse('global')->covers(Scope::parse($target)))->toBeTrue();
    })->with(['global', 'server:prod-mysql', 'database:prod-mysql/shop_prod', 'server:staging', 'database:staging/x']);

    it('a server scope covers itself and its databases, but not other servers', function (): void {
        $server = Scope::parse('server:prod-mysql');

        expect($server->covers(Scope::parse('server:prod-mysql')))->toBeTrue()
            ->and($server->covers(Scope::parse('database:prod-mysql/shop_prod')))->toBeTrue()
            ->and($server->covers(Scope::parse('database:prod-mysql/anything')))->toBeTrue()
            ->and($server->covers(Scope::parse('server:prod-postgres')))->toBeFalse()
            ->and($server->covers(Scope::parse('database:prod-postgres/x')))->toBeFalse()
            ->and($server->covers(Scope::parse('global')))->toBeFalse();   // narrower never covers wider
    });

    it('a database scope covers only matching databases on its server', function (): void {
        $exact = Scope::parse('database:prod-mysql/shop_prod');

        expect($exact->covers(Scope::parse('database:prod-mysql/shop_prod')))->toBeTrue()
            ->and($exact->covers(Scope::parse('database:prod-mysql/other_db')))->toBeFalse()
            ->and($exact->covers(Scope::parse('database:prod-postgres/shop_prod')))->toBeFalse()
            ->and($exact->covers(Scope::parse('server:prod-mysql')))->toBeFalse()   // db never covers server
            ->and($exact->covers(Scope::parse('global')))->toBeFalse();
    });

    it('a database wildcard covers matching-prefix databases only', function (): void {
        $pattern = Scope::parse('database:prod-mysql/shop_*');

        expect($pattern->covers(Scope::parse('database:prod-mysql/shop_prod')))->toBeTrue()
            ->and($pattern->covers(Scope::parse('database:prod-mysql/shop_reporting')))->toBeTrue()
            ->and($pattern->covers(Scope::parse('database:prod-mysql/analytics')))->toBeFalse()
            ->and($pattern->covers(Scope::parse('database:prod-mysql/shop')))->toBeFalse()  // 'shop' lacks the 'shop_' prefix
            ->and($pattern->covers(Scope::parse('database:other/shop_prod')))->toBeFalse();
    });
});
