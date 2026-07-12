<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Engines\Quoter;
use Simtabi\Laranail\DBConsole\Enums\EngineType;

describe('per-dialect identifier quoting', function (): void {
    it('uses backticks for the MySQL family', function (EngineType $engine): void {
        expect(Quoter::for($engine)->identifier('shop_prod'))->toBe('`shop_prod`');
    })->with([EngineType::Mysql, EngineType::Mariadb]);

    it('uses double quotes for Postgres and SQLite', function (EngineType $engine): void {
        expect(Quoter::for($engine)->identifier('shop_prod'))->toBe('"shop_prod"');
    })->with([EngineType::Pgsql, EngineType::Sqlite]);

    it('uses brackets for SQL Server', function (): void {
        expect(Quoter::for(EngineType::Sqlsrv)->identifier('shop_prod'))->toBe('[shop_prod]');
    });
});

describe('defensive quote doubling (belt and suspenders over the value-object allow-list)', function (): void {
    it('doubles backticks for MySQL', function (): void {
        expect(Quoter::for(EngineType::Mysql)->identifier('a`b'))->toBe('`a``b`');
    });

    it('doubles double quotes for Postgres', function (): void {
        expect(Quoter::for(EngineType::Pgsql)->identifier('a"b'))->toBe('"a""b"');
    });

    it('doubles closing brackets for SQL Server', function (): void {
        expect(Quoter::for(EngineType::Sqlsrv)->identifier('a]b'))->toBe('[a]]b]');
    });

    it('doubles single quotes in string literals', function (): void {
        expect(Quoter::for(EngineType::Mysql)->literal("O'Brien"))->toBe("'O''Brien'");
    });
});
