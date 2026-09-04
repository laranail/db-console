<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Engines\PostgresEngine;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

function pgSql(iterable $list): array
{
    $out = [];
    foreach ($list as $s) {
        $out[] = $s->sql;
    }

    return $out;
}

$engine = new PostgresEngine;

it('declares the Postgres account model honestly (role, no host scoping)', function () use ($engine): void {
    $caps = $engine->capabilities();

    expect($engine->type())->toBe(EngineType::Pgsql)
        ->and($caps->canCreateAccount)->toBeTrue()
        ->and($caps->canScopeAccountsByHost)->toBeFalse()   // host is pg_hba, not DBConsole
        ->and($caps->accountModelNote)->toContain('pg_hba');
});

it('creates a database with double-quoted identifier and encoding', function () use ($engine): void {
    expect(pgSql($engine->createDatabase(new DbName('shop_prod'), new Charset('utf8'))))
        ->toBe(['CREATE DATABASE "shop_prod" ENCODING \'UTF8\'']);
});

it('creates a role WITH LOGIN, redacting the password', function () use ($engine): void {
    $statements = $engine->createAccount(new Username('report_user'), new Host('%'), new Password('Xk9$mQ2vLpW7#nR4t!'))->all();

    expect($statements[0]->sql)->toBe('CREATE ROLE "report_user" WITH LOGIN PASSWORD \'Xk9$mQ2vLpW7#nR4t!\'')
        ->and($statements[0]->redacted)->toBe('CREATE ROLE "report_user" WITH LOGIN PASSWORD \'[redacted]\'')
        ->and($statements[0]->redacted)->not->toContain('Xk9');
});

it('drops a role and a database', function () use ($engine): void {
    expect(pgSql($engine->dropAccount(new Username('report_user'), new Host('%'))))->toBe(['DROP ROLE "report_user"'])
        ->and(pgSql($engine->dropDatabase(new DbName('shop_prod'))))->toBe(['DROP DATABASE "shop_prod"']);
});

it('grants database-scoped privileges, never *.* / server-wide', function () use ($engine): void {
    $sql = pgSql($engine->grant(
        PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite),
        new DbName('analytics'),
        new Username('report_user'),
        new Host('%'),
    ));

    expect($sql[0])->toBe('GRANT CONNECT ON DATABASE "analytics" TO "report_user"')
        ->and($sql[1])->toContain('ON ALL TABLES IN SCHEMA public TO "report_user"')
        ->and(implode(' ', $sql))->not->toContain('SUPERUSER')
        ->and(implode(' ', $sql))->not->toContain('*.*');
});
